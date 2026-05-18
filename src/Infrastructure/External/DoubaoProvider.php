<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use App\Common\PerfTrace;
use GuzzleHttp\Client;
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
 * RAG用途のembeddingsも提供。
 * 公式プロバイダーが要件を満たさない場合のカスタム実装として使用。
 */
class DoubaoProvider
{
    private Client $httpClient;
    private string $apiKey;
    private string $chatModel;
    private float $temperature;
    private int $maxTokens;
    private int $timeout;
    private int $maxRetries;
    private LoggerInterface $logger;

    // API エンドポイントとモデル定義
    private const BASE_URI          = 'https://ark.cn-beijing.volces.com';
    private const CHAT_PATH         = '/api/v3/chat/completions';
    private const EMBEDDING_PATH    = '/api/v3/embeddings/multimodal';
    private const EMBEDDING_MODEL   = 'doubao-embedding-vision-250615';
    private const EMBEDDING_DIM     = 2048;

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
        string $apiKey,
        LoggerInterface $logger,
        string $chatModel = 'doubao-1-5-lite-32k-250115',
        float $temperature = 0.7,
        int $maxTokens = 256,
        int $timeout = 15,
        int $maxRetries = 1
    ) {
        $this->apiKey      = $apiKey;
        $this->chatModel   = $chatModel;
        $this->logger      = $logger;
        $this->temperature = $temperature;
        $this->maxTokens   = $maxTokens;
        $this->timeout     = $timeout;
        $this->maxRetries  = $maxRetries;

        $handlerStack = HandlerStack::create();

        // リトライ処理：最大リトライ回数に従う（遅延300msで簡素化）
        $handlerStack->push(Middleware::retry(
            function (int $retries): bool {
                return $retries < $this->maxRetries;
            },
            fn(int $retries): int => 300 // バックオフ500ms→300ms
        ));

        // Guzzle クライアント設定：長接続・短い接続タイムアウト・HTTPエラーをスローしない
        $this->httpClient = new Client([
            'handler'         => $handlerStack,
            'base_uri'        => self::BASE_URI,
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

    /**
     * チャット完了APIを呼び出す（ストリーミング対応）
     *
     * @param array $messages
     * @param array $tools
     * @param callable|null $onToken fn(string $token): void
     * @return array ['role'=>…, 'content'=>…, 'tool_calls'=>…]
     */
    public function chat(array $messages, array $tools = [], ?callable $onToken = null): array
    {
        $tApi = PerfTrace::now();
        $formattedMessages = $this->formatMessagesForDoubao($messages);

        $payload = [
            'model'       => $this->chatModel,
            'messages'    => $formattedMessages,
            'temperature' => $this->temperature,
            'max_tokens'  => $this->maxTokens,
            'stream'      => true,
        ];

        if (!empty($tools)) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = $this->httpClient->post(self::CHAT_PATH, [
                'json'   => $payload,
                'stream' => true,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $errorBody = json_decode((string) $response->getBody(), true)
                           ?: ['error' => 'Unknown error'];
                throw new \RuntimeException(sprintf(
                    'Doubao API %d: %s',
                    $statusCode,
                    json_encode($errorBody)
                ));
            }

            $body        = $response->getBody();
            $fullContent = '';
            $toolCalls   = [];
            $usage       = [];
            $role        = 'assistant';
            $lineBuffer  = '';

            while (!$body->eof()) {
                $chunk      = $body->read(512);
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

                    $chunk = json_decode($raw, true);
                    if (!is_array($chunk)) {
                        continue;
                    }

                    if (isset($chunk['usage'])) {
                        $usage = $chunk['usage'];
                    }

                    $delta = $chunk['choices'][0]['delta'] ?? [];
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
                            $toolCalls[$idx]['function']['name']
                                .= $tc['function']['name'] ?? '';
                            $toolCalls[$idx]['function']['arguments']
                                .= $tc['function']['arguments'] ?? '';
                        }
                    }
                }
            }

            $this->logger->info('Doubao Usage', $usage);
            PerfTrace::log('LLM.DoubaoChat', $tApi, [
                'model'             => $this->chatModel,
                'msg_count'         => count($messages),
                'has_tools'         => !empty($tools) ? 'yes' : 'no',
                'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
            ]);

            return [
                'role'       => $role,
                'content'    => $fullContent ?: null,
                'tool_calls' => array_values($toolCalls),
            ];
        } catch (GuzzleException | \JsonException $e) {
            PerfTrace::log('LLM.DoubaoChat', $tApi, [
                'status' => 'error',
                'error'  => $e->getMessage(),
            ]);
            $this->logger->error('Doubao chat failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Doubao chat request failed', 0, $e);
        }
    }

    /**
     * テキストをエンベディング（ベクトル）に変換する
     *
     * @param string $text 入力テキスト
     * @return array 固定次元（2048）のfloat配列。失敗時はゼロベクトル
     */
    public function getEmbedding(string $text): array
    {
        $tApi = PerfTrace::now();
        $text = trim($text);
        if ($text === '') {
            return array_fill(0, self::EMBEDDING_DIM, 0.0);
        }

        try {
            $payload = [
                'model' => self::EMBEDDING_MODEL,
                'input' => [['type' => 'text', 'text' => $text]],
            ];

            $response = $this->httpClient->post(self::EMBEDDING_PATH, ['json' => $payload]);
            
            if ($response->getStatusCode() !== 200) {
                $error = json_decode((string)$response->getBody(), true) ?: ['error' => 'HTTP error'];
                $this->logger->error('Doubao embedding HTTP error', [
                    'status' => $response->getStatusCode(),
                    'error'  => $error,
                ]);
                return array_fill(0, self::EMBEDDING_DIM, 0.0);
            }

            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $embedding = $data['data'][0]['embedding'] ?? [];
            $len = count($embedding);

            PerfTrace::log('Embedding.Doubao', $tApi, [
                'text_length' => strlen($text),
                'vector_dims' => $len,
            ]);

            if ($len === self::EMBEDDING_DIM) {
                return $embedding;
            }

            if ($len > self::EMBEDDING_DIM) {
                return array_slice($embedding, 0, self::EMBEDDING_DIM);
            }

            return array_merge($embedding, array_fill(0, self::EMBEDDING_DIM - $len, 0.0));

        } catch (\Throwable $e) {
            PerfTrace::log('Embedding.Doubao', $tApi, ['status' => 'error', 'text_length' => strlen($text)]);
            $this->logger->error('Doubao embedding failed', [
                'error'       => $e->getMessage(),
            ]);
            return array_fill(0, self::EMBEDDING_DIM, 0.0);
        }
    }

    /**
     * メッセージをDoubao API向けにフォーマット
     *   - contentが文字列の場合、自動的にテキスト型配列に変換
     *   - 処理負荷軽減のため簡素化
     *
     * @param array $messages 元のメッセージ配列
     * @return array 変換後メッセージ
     */
    private function formatMessagesForDoubao(array $messages): array
    {
        $formatted = [];
        
        foreach ($messages as $msg) {
            $formattedMsg = [
                'role' => $msg['role'] ?? 'user'
            ];
            
            if (isset($msg['content'])) {
                if (is_string($msg['content'])) {
                    $formattedMsg['content'] = [
                        [
                            'type' => 'text',
                            'text' => $msg['content']
                        ]
                    ];
                } else {
                    $formattedMsg['content'] = $msg['content'];
                }
            }
            
            $formatted[] = $formattedMsg;
        }
        
        return $formatted;
    }
}