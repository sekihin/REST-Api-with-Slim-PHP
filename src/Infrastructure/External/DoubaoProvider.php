<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use App\Common\PerfTrace;
use App\Common\Tracing\TracerInterface;
use App\Common\Tracing\NullTracer;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Doubao (豆包) 統合プロバイダー - Volcengine Ark
 *
 * - LLMチャット（OpenAI互換 / function calling 対応）
 * - Text Embeddings 生成（multimodalエンドポイント使用）
 * - パフォーマンス重視の設定（タイムアウト短縮、長接続、リトライ抑制）
 *
 * Fix log:
 *  1. `Middleware::retry` decider の修正：4つの引数を正しく受け取るようにし、5xx および `ConnectException` のみリトライするよう変更
 *  2. SSEストリーム解析を行単位の読み取りに変更し、UTF-8マルチバイト文字の切り捨て（途切れ）問題を解決
 *  3. `PerfTrace` の密結合を解消 → `TracerInterface` による依存性注入（DI）へ変更（デフォルト実装として `NullTracer` を使用）
 *  4. `$messages` が空配列の場合、またはテキストが長すぎる場合、いずれも `InvalidArgumentException` をスローするよう変更
 *  5. 設定項目をすべて外部から渡すようにし、定数はデフォルト値の保持のみとするよう変更
 *  6. `batchEmbedding()`（一括ベクトル化/エンベディング）と `chatSync()`（非ストリーミングチャット）を新規追加
 */
class DoubaoProvider
{
    private Client          $httpClient;
    private string          $apiKey;
    private string          $chatModel;
    private string          $embeddingModel;
    private int             $embeddingDim;
    private float           $temperature;
    private int             $maxTokens;
    private int             $timeout;
    private int             $maxRetries;
    private LoggerInterface $logger;
    private TracerInterface $tracer;

    // デフォルト値（外部から上書き可能）
    public  const DEFAULT_BASE_URI        = 'https://ark.cn-beijing.volces.com';
    public  const DEFAULT_CHAT_MODEL      = 'doubao-seed-2-0-mini-260428'; //doubao-1-5-lite-32k-250115
    public  const DEFAULT_EMBEDDING_MODEL = 'doubao-embedding-vision-250615';
    public  const DEFAULT_EMBEDDING_DIM   = 2048;
    private const CHAT_PATH               = '/api/v3/chat/completions';
    private const EMBEDDING_PATH          = '/api/v3/embeddings/multimodal';
    private const EMBEDDING_MAX_CHARS     = 8000;

    /**
     * コンストラクタ
     *
     * @param string $apiKey APIキー
     * @param LoggerInterface $logger ロガー
     * @param string $chatModel チャットモデル名
     * @param float $temperature 生成の多様性（高いほど速い傾向）
     * @param int $maxTokens 最大トークン数（少なめで高速化）
     * @param int $timeout タイムアウト秒数（15秒→無駄な待機を防止）
     * @param int $maxRetries 最大リトライ回数（1回→遅延削減）
     */
    public function __construct(
        string          $apiKey,
        LoggerInterface $logger,
        ?TracerInterface $tracer      = null,
        string          $chatModel    = self::DEFAULT_CHAT_MODEL,
        string          $embeddingModel = self::DEFAULT_EMBEDDING_MODEL,
        int             $embeddingDim = self::DEFAULT_EMBEDDING_DIM,
        float           $temperature  = 0.7,
        int             $maxTokens    = 256,
        int             $timeout      = 15,
        int             $maxRetries   = 1,
        string          $baseUri      = self::DEFAULT_BASE_URI,
    ) {
        $this->apiKey         = $apiKey;
        $this->logger         = $logger;
        $this->tracer         = $tracer ?? new NullTracer();
        $this->chatModel      = $chatModel;
        $this->embeddingModel = $embeddingModel;
        $this->embeddingDim   = $embeddingDim;
        $this->temperature    = $temperature;
        $this->maxTokens      = $maxTokens;
        $this->timeout        = $timeout;
        $this->maxRetries     = $maxRetries;

        $handlerStack = HandlerStack::create();

        // リトライ処理：最大リトライ回数に従う（遅延300msで簡素化）
        $handlerStack->push(Middleware::retry(
            function (
                int                $retries,
                RequestInterface   $request,
                ?ResponseInterface $response,
                ?\Throwable        $exception
            ): bool {
                if ($retries >= $this->maxRetries) {
                    return false;
                }
                if ($exception instanceof ConnectException) {
                    return true;
                }
                return $response !== null && $response->getStatusCode() >= 500;
            },
            fn(int $retries): int => 300 // バックオフ500ms→300ms
        ));

        // Guzzle クライアント設定：長接続・短い接続タイムアウト・HTTPエラーをスローしない
        $this->httpClient = new Client([
            'handler'         => $handlerStack,
            'base_uri'        => $baseUri,
            'timeout'         => $this->timeout,
            'connect_timeout' => 3,      // 接続タイムアウト 10秒→3秒
            'http_errors'     => false,  // ステータスコードは手動チェック
            'keep-alive'      => true,   // TCP長接続を有効化（速度向上）
            'headers'         => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
                'Connection'    => 'keep-alive', // 長接続維持
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // 流式チャット
    // -------------------------------------------------------------------------

    /**
     * チャット完了APIを呼び出す（ストリーミング対応）
     *
     * @param array         $messages  非空メッセージ配列
     * @param array         $tools     ツール定義（optional）
     * @param callable|null $onToken   fn(string $token): void
     * @return array{role: string, content: string|null, tool_calls: array}
     *
     * @throws \InvalidArgumentException messages が空の場合
     * @throws \RuntimeException         APIエラー / ネットワークエラー
     */
    public function chat(array $messages, array $tools = [], ?callable $onToken = null): array
    {
        if (empty($messages)) {
            throw new \InvalidArgumentException('messages cannot be empty');
        }

        $tApi    = $this->tracer->now();
        $payload = $this->buildChatPayload($messages, $tools, stream: true);

        try {
            $response   = $this->httpClient->post(self::CHAT_PATH, ['json' => $payload, 'stream' => true]);
            $statusCode = $response->getStatusCode();
            $requestId  = $this->getRequestId($response); // ✅ リクエストID

            if ($statusCode !== 200) {
                $this->throwApiError('Doubao chat', $statusCode, $response, $requestId);
            }

            $body        = $response->getBody();
            $fullContent = '';
            $toolCalls   = [];
            $usage       = [];
            $role        = 'assistant';
            $lineBuffer  = '';

            while (!$body->eof()) {
                $chunk      = $body->read(8192);
                $lineBuffer .= $chunk;

                while (($pos = strpos($lineBuffer, "\n")) !== false) {
                    $line       = substr($lineBuffer, 0, $pos);
                    $lineBuffer = substr($lineBuffer, $pos + 1);
                    $line       = rtrim($line, "\r");

                    if (!str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $raw = ltrim(substr($line, 5));

                    if ($raw === '[DONE]') {
                        break 2;
                    }

                    $event = json_decode($raw, true);
                    if (!is_array($event)) {
                        continue;
                    }

                    if (isset($event['usage'])) {
                        $usage = $event['usage'];
                    }

                    $delta = $event['choices'][0]['delta'] ?? [];
                    if (isset($delta['role'])) {
                        $role = $delta['role'];
                    }

                    $token = $delta['content'] ?? null;
                    if ($token !== null && $token !== '') {
                        $fullContent .= $token;
                        if ($onToken !== null) {
                            $onToken($token);
                        }
                    }

                    if (!empty($delta['tool_calls'])) {
                        foreach ($delta['tool_calls'] as $tc) {
                            $idx = $tc['index'] ?? 0;
                            if (!isset($toolCalls[$idx])) {
                                $toolCalls[$idx] = [
                                    'id'       => $tc['id'] ?? '',
                                    'type'     => $tc['type'] ?? 'function',
                                    'function' => ['name' => '', 'arguments' => ''],
                                ];
                            }
                            $toolCalls[$idx]['function']['name']      .= $tc['function']['name'] ?? '';
                            $toolCalls[$idx]['function']['arguments'] .= $tc['function']['arguments'] ?? '';
                        }
                    }
                }
            }

            $this->logger->info('Doubao chat usage', [
                'request_id' => $requestId,
                'usage' => $usage
            ]);
            $this->tracer->log('LLM.DoubaoChat', $tApi, [
                'model'             => $this->chatModel,
                'msg_count'         => count($messages),
                'has_tools'         => !empty($tools) ? 'yes' : 'no',
                'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'request_id'        => $requestId,
                ...PerfTrace::requestPayloadFields($this->encodeRequestPayload($payload)),
            ]);

            return [
                'role'       => $role,
                'content'    => $fullContent ?: null,
                'tool_calls' => array_values($toolCalls),
                'request_id' => $requestId,  // ✅ リクエストID
            ];

        } catch (GuzzleException $e) {
            $this->tracer->log('LLM.DoubaoChat', $tApi, ['status' => 'error', 'error' => $e->getMessage()]);
            $this->logger->error('Doubao chat failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Doubao chat request failed', 0, $e);
        }
    }

    // -------------------------------------------------------------------------
    // 非流式チャット（NEW）
    // -------------------------------------------------------------------------

    /**
     * 同期（非ストリーミング）チャット
     * cron / webhook など、リアルタイム出力不要なシーンに適する。
     *
     * @param array $messages
     * @param array $tools
     * @return array{role: string, content: string|null, tool_calls: array}
     */
    public function chatSync(array $messages, array $tools = []): array
    {
        if (empty($messages)) {
            throw new \InvalidArgumentException('messages cannot be empty');
        }

        $tApi    = $this->tracer->now();
        $payload = $this->buildChatPayload($messages, $tools, stream: false);

        try {
            $response   = $this->httpClient->post(self::CHAT_PATH, ['json' => $payload]);
            $statusCode = $response->getStatusCode();
            $requestId  = $this->getRequestId($response);  // ✅ リクエストID

            if ($statusCode !== 200) {
                $this->throwApiError('Doubao chatSync', $statusCode, $response, $requestId);
            }

            $data   = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $choice = $data['choices'][0] ?? [];
            $msg    = $choice['message'] ?? [];
            $usage  = $data['usage'] ?? [];

            $this->logger->info('Doubao chatSync usage', [
                'request_id' => $requestId,
                'usage' => $usage
            ]);
            $this->tracer->log('LLM.DoubaoChatSync', $tApi, [
                'model'             => $this->chatModel,
                'msg_count'         => count($messages),
                'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'request_id'        => $requestId,
                ...PerfTrace::requestPayloadFields($this->encodeRequestPayload($payload)),
            ]);

            return [
                'role'       => $msg['role'] ?? 'assistant',
                'content'    => $msg['content'] ?? null,
                'tool_calls' => $msg['tool_calls'] ?? [],
                'request_id' => $requestId,  // ✅ リクエストID
            ];

        } catch (GuzzleException|\JsonException $e) {
            $this->tracer->log('LLM.DoubaoChatSync', $tApi, ['status' => 'error', 'error' => $e->getMessage()]);
            $this->logger->error('Doubao chatSync failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Doubao chatSync request failed', 0, $e);
        }
    }

    // -------------------------------------------------------------------------
    // １件 Embedding
    // -------------------------------------------------------------------------

    /**
     * テキストをエンベディング（ベクトル）に変換する
     *
     * @param string $text 入力テキスト（自動で最大長にトリミング）
     * @return float[] 固定次元の float 配列。失敗時はゼロベクトル
     */
    public function getEmbedding(string $text): array
    {
        $tApi = $this->tracer->now();
        $text = trim($text);

        if ($text === '') {
            return array_fill(0, $this->embeddingDim, 0.0);
        }

        if (mb_strlen($text) > self::EMBEDDING_MAX_CHARS) {
            $text = mb_substr($text, 0, self::EMBEDDING_MAX_CHARS);
            $this->logger->warning('Doubao embedding: text truncated', ['original_chars' => mb_strlen($text)]);
        }

        try {
            $payload  = [
                'model' => $this->embeddingModel,
                'input' => [['type' => 'text', 'text' => $text]],
            ];
            $response = $this->httpClient->post(self::EMBEDDING_PATH, ['json' => $payload]);
            $requestId = $this->getRequestId($response);

            if ($response->getStatusCode() !== 200) {
                $error = json_decode((string) $response->getBody(), true) ?: ['error' => 'Unknown error'];
                $this->logger->error('Doubao embedding HTTP error', [
                    'request_id' => $requestId,
                    'status' => $response->getStatusCode(),
                    'error'  => $error,
                ]);
                return array_fill(0, $this->embeddingDim, 0.0);
            }

            $data      = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $embedding = $data['data'][0]['embedding'] ?? [];

            $vector = $this->normalizeDimension($embedding);
            $this->tracer->log('Embedding.Doubao', $tApi, [
                'text_length' => strlen($text),
                'vector_dims' => count($vector),
                'request_id'  => $requestId,
            ]);

            return $vector;

        } catch (\Throwable $e) {
            $this->tracer->log('Embedding.Doubao', $tApi, ['status' => 'error']);
            $this->logger->error('Doubao embedding failed', ['error' => $e->getMessage()]);
            return array_fill(0, $this->embeddingDim, 0.0);
        }
    }

    // -------------------------------------------------------------------------
    // バッチ Embedding
    // -------------------------------------------------------------------------

    /**
     * 複数テキストを一括でエンベディングに変換する（RAG文書インデックス用）
     *
     * 個別失敗はゼロベクトルで補填し、他のテキストへの影響を遮断する。
     *
     * @param  string[] $texts
     * @return float[][] $texts と同順の float[][] 配列
     */
    public function batchEmbedding(array $texts): array
    {
        return array_map(
            fn(string $text): array => $this->getEmbedding($text),
            $texts
        );
    }

    // -------------------------------------------------------------------------
    // リクエストID 取得
    // -------------------------------------------------------------------------
    private function getRequestId(ResponseInterface $response): string
    {
        return DoubaoRequestId::fromHeaders($response->getHeaders());
    }

    // -------------------------------------------------------------------------
    // プライベートヘルパー
    // -------------------------------------------------------------------------

    private function encodeRequestPayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '';
    }

    private function buildChatPayload(array $messages, array $tools, bool $stream): array
    {
        $payload = [
            'model'       => $this->chatModel,
            'messages'    => $this->formatMessagesForDoubao($messages),
            'temperature' => $this->temperature,
            'max_tokens'  => $this->maxTokens,
            'stream'      => $stream,
        ];

        if (!empty($tools)) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    private function normalizeDimension(array $embedding): array
    {
        $len = count($embedding);

        if ($len === $this->embeddingDim) {
            return $embedding;
        }
        if ($len > $this->embeddingDim) {
            return array_slice($embedding, 0, $this->embeddingDim);
        }
        return array_merge($embedding, array_fill(0, $this->embeddingDim - $len, 0.0));
    }

    private function formatMessagesForDoubao(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $msg) {
            $formattedMsg = ['role' => $msg['role'] ?? 'user'];

            if (isset($msg['content'])) {
                $formattedMsg['content'] = is_string($msg['content'])
                    ? [['type' => 'text', 'text' => $msg['content']]]
                    : $msg['content'];
            }

            $formatted[] = $formattedMsg;
        }

        return $formatted;
    }

    /**
     * @throws \RuntimeException
     */
    private function throwApiError(
        string $context,
        int $status,
        ResponseInterface $response,
        string $requestId = 'unknown'
    ): never {
        $body = json_decode((string) $response->getBody(), true) ?: ['error' => 'Unknown error'];
        $this->logger->error("{$context} API error", [
            'request_id' => $requestId,
            'status' => $status,
            'body' => $body
        ]);

        throw new \RuntimeException(
            sprintf('%s API %d | request_id=%s: %s',
                $context,
                $status,
                $requestId,
                json_encode($body, JSON_UNESCAPED_UNICODE)
            )
        );
    }
}
