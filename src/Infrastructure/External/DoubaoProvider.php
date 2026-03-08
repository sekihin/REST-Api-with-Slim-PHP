<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

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

    /** @var string 豆包 APIの正しいエンドポイント */
    // 修正1: 火山引擎方舟APIの正しい基礎URLに変更
    private const BASE_URI          = 'https://ark.cn-beijing.volces.com';
    private const CHAT_PATH         = '/api/v3/chat/completions';       // 修正2: チャットエンドポイントの正しいパス
    private const EMBEDDING_PATH    = '/api/v3/embeddings/multimodal'; // 修正3: Embeddingエンドポイントの正しいパス
    private const EMBEDDING_MODEL   = 'doubao-embedding-vision-250615';
    private const EMBEDDING_DIM     = 2048;

    /**
     * コンストラクタ
     * @param string $apiKey      豆包 APIキー
     * @param LoggerInterface $logger ロガー
     * @param string $chatModel   モデル名
     * @param float  $temperature サンプリング温度
     * @param int    $maxTokens   最大生成トークン数
     * @param int    $timeout     HTTPリクエストのタイムアウト秒数
     * @param int    $maxRetries  再試行回数
     */
    public function __construct(
        string $apiKey,
        LoggerInterface $logger,
        string $chatModel = 'doubao-seed-2-0-mini-260215',
        float $temperature = 0.5,
        int $maxTokens = 2048,
        int $timeout = 60,
        int $maxRetries = 3
    ) {
        $this->apiKey      = $apiKey;
        $this->chatModel   = $chatModel;
        $this->logger      = $logger;
        $this->temperature = $temperature;
        $this->maxTokens   = $maxTokens;
        $this->timeout     = $timeout;
        $this->maxRetries  = $maxRetries;

        // Guzzleクライアントの初期化
        $handlerStack = HandlerStack::create();
        $handlerStack->push(Middleware::retry(
            function (
                int $retries,
                RequestInterface $request,
                ?ResponseInterface $response = null,
                ?GuzzleException $exception = null
            ): bool {
                if ($retries >= $this->maxRetries) {
                    return false;
                }
                if ($response && $response->getStatusCode() >= 500) {
                    return true;
                }
                return $exception !== null;
            },
            fn(int $retries): int => 500 * $retries
        ));

        $this->httpClient = new Client([
            'handler'         => $handlerStack,
            'base_uri'        => self::BASE_URI,
            'timeout'         => $this->timeout,
            'connect_timeout' => 10,
            'http_errors'     => false,
            'headers'         => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ]);
    }

    /**
     * チャットリクエストの送信
     */
    public function chat(array $messages, array $tools = []): array
    {
        // リクエストメッセージを豆包APIの仕様に変換
        $formattedMessages = $this->formatMessagesForDoubao($messages);
        
        // リクエストペイロードの構築
        $payload = [
            'model'            => $this->chatModel,
            'messages'         => $formattedMessages,
            'temperature'      => $this->temperature,
            'max_tokens'       => $this->maxTokens,
            'stream'           => false,
            'reasoning_effort' => 'medium',
        ];

        if (!empty($tools)) {
            $payload['tools']      = $tools;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $this->logger->debug('Doubao Chat Request', [
                'model'     => $this->chatModel,
                'msg_count' => count($messages),
                'has_tools' => !empty($tools),
                'payload'   => $payload,
                'endpoint'  => self::CHAT_PATH
            ]);

            // 修正4: 正しいエンドポイントパスを使用
            $response = $this->httpClient->post(self::CHAT_PATH, ['json' => $payload]);
            
            // HTTPステータスコードのチェック
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $errorBody = json_decode((string)$response->getBody(), true) ?: ['error' => 'Unknown error'];
                $errorMsg = sprintf(
                    'Doubao API returned non-200 status: %d, error: %s',
                    $statusCode,
                    json_encode($errorBody)
                );
                $this->logger->error('Doubao chat HTTP error', [
                    'status_code' => $statusCode,
                    'error'       => $errorBody,
                    'endpoint'    => self::CHAT_PATH
                ]);
                throw new \RuntimeException($errorMsg);
            }

            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($body['choices'][0]['message'])) {
                $this->logger->error('Doubao invalid response format', ['response' => $body]);
                throw new \RuntimeException('Invalid response format: choices[0].message missing');
            }

            $choice = $body['choices'][0]['message'];
            $usage  = $body['usage'] ?? [];
            $this->logger->info('Doubao Usage', $usage);

            return [
                'role'       => $choice['role'] ?? 'assistant',
                'content'    => $choice['content'] ?? null,
                'tool_calls' => $choice['tool_calls'] ?? [],
            ];

        } catch (GuzzleException | \JsonException $e) {
            $this->logger->error('Doubao chat failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('Doubao chat request failed', 0, $e);
        }
    }

    /**
     * テキストをEmbedding化
     */
    public function getEmbedding(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return array_fill(0, self::EMBEDDING_DIM, 0.0);
        }

        try {
            $payload = [
                'model' => self::EMBEDDING_MODEL,
                'input' => [['type' => 'text', 'text' => $text]],
            ];

            // 修正5: Embeddingの正しいエンドポイントを使用
            $response = $this->httpClient->post(self::EMBEDDING_PATH, ['json' => $payload]);
            
            if ($response->getStatusCode() !== 200) {
                $error = json_decode((string)$response->getBody(), true) ?: ['error' => 'HTTP error'];
                $this->logger->error('Doubao embedding HTTP error', [
                    'status' => $response->getStatusCode(),
                    'error'  => $error,
                    'endpoint' => self::EMBEDDING_PATH
                ]);
                return array_fill(0, self::EMBEDDING_DIM, 0.0);
            }

            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $embedding = $data['data'][0]['embedding'] ?? [];
            $len = count($embedding);

            if ($len === self::EMBEDDING_DIM) {
                return $embedding;
            }

            if ($len > self::EMBEDDING_DIM) {
                return array_slice($embedding, 0, self::EMBEDDING_DIM);
            }

            return array_merge($embedding, array_fill(0, self::EMBEDDING_DIM - $len, 0.0));

        } catch (\Throwable $e) {
            $this->logger->error('Doubao embedding failed', [
                'error'       => $e->getMessage(),
                'text_length' => strlen($text),
                'trace'       => $e->getTraceAsString()
            ]);
            return array_fill(0, self::EMBEDDING_DIM, 0.0);
        }
    }

    /**
     * メッセージフォーマット変換
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