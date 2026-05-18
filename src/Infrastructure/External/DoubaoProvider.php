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
        string $chatModel = 'doubao-seed-2-0-mini-260215',
        float $temperature = 0.7,
        int $maxTokens = 1024,
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
     * チャット完了APIを呼び出す
     *
     * @param array $messages メッセージ配列（role/content）
     * @param array $tools ツール定義（任意）
     * @return array 応答メッセージ（role, content, tool_calls）
     * @throws \RuntimeException APIリクエスト失敗時
     */
    public function chat(array $messages, array $tools = []): array
    {
        $tApi = PerfTrace::now();
        $formattedMessages = $this->formatMessagesForDoubao($messages);

        // ペイロード作成：推論を遅らせる 'reasoning_effort' は意図的に除外
        $payload = [
            'model'            => $this->chatModel,
            'messages'         => $formattedMessages,
            'temperature'      => $this->temperature,
            'max_tokens'       => $this->maxTokens,
            'stream'           => false,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = $this->httpClient->post(self::CHAT_PATH, ['json' => $payload]);
            $statusCode = $response->getStatusCode();

            // エラーレスポンス処理
            if ($statusCode !== 200) {
                $errorBody = json_decode((string)$response->getBody(), true) ?: ['error' => 'Unknown error'];
                $this->logger->error('Doubao chat error', ['status' => $statusCode, 'error' => $errorBody]);
                throw new \RuntimeException('API request failed');
            }

            $body = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $choice = $body['choices'][0]['message'];
            $usage  = $body['usage'] ?? [];

            // パフォーマンス計測：トークン使用量を記録
            PerfTrace::log('LLM.DoubaoChat', $tApi, [
                'model' => $this->chatModel,
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
            ]);

            return [
                'role'       => $choice['role'] ?? 'assistant',
                'content'    => $choice['content'] ?? null,
                'tool_calls' => $choice['tool_calls'] ?? [],
            ];

        } catch (GuzzleException | \JsonException $e) {
            PerfTrace::log('LLM.DoubaoChat', $tApi, ['status' => 'error']);
            $this->logger->error('Doubao chat failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Doubao request failed', 0, $e);
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
        if ($text === '') return array_fill(0, self::EMBEDDING_DIM, 0.0);

        try {
            $payload = [
                'model' => self::EMBEDDING_MODEL,
                'input' => [['type' => 'text', 'text' => $text]],
            ];

            $response = $this->httpClient->post(self::EMBEDDING_PATH, ['json' => $payload]);
            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Doubao embedding error', ['status' => $response->getStatusCode()]);
                return array_fill(0, self::EMBEDDING_DIM, 0.0);
            }

            $data = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $embedding = $data['data'][0]['embedding'] ?? [];
            $len = count($embedding);

            PerfTrace::log('Embedding.Doubao', $tApi, ['text_len' => strlen($text), 'dims' => $len]);

            // 次元数が2048になるよう調整（切り詰め or 0埋め）
            return match(true) {
                $len === self::EMBEDDING_DIM => $embedding,
                $len > self::EMBEDDING_DIM => array_slice($embedding, 0, self::EMBEDDING_DIM),
                default => array_merge($embedding, array_fill(0, self::EMBEDDING_DIM - $len, 0.0))
            };

        } catch (\Throwable $e) {
            $this->logger->error('Doubao embedding failed', ['error' => $e->getMessage()]);
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
            $formatted[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => is_string($msg['content'] ?? '')
                    ? [['type' => 'text', 'text' => $msg['content']]]
                    : $msg['content']
            ];
        }
        return $formatted;
    }
}