<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Neuron\Providers\LLM\LLMInterface; // Neuronフレームワークで定義されたインターフェースと仮定
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * DeepSeek LLMプロバイダー
 * * DeepSeek V3 (deepseek-chat) モデルとの通信を実装するクラスです。
 * * DeepSeekのAPIインターフェースはOpenAIと互換性がありますが、Base URLや推奨パラメータが異なります。
 * * 低コストかつ高性能な推論エンジンとして、システムに統合されます。
 */
class DeepSeekProvider implements LLMInterface
{
    /** @var Client HTTPクライアント (Guzzle) */
    private Client $httpClient;
    
    private string $apiKey;
    private string $model;
    private float $temperature;
    private int $maxTokens;
    private int $timeout;
    private int $maxRetries;
    private LoggerInterface $logger;

    /** @var string DeepSeek APIのエンドポイント */
    private const BASE_URI = 'https://api.deepseek.com';

    /**
     * コンストラクタ
     * * @param string $apiKey      DeepSeek APIキー
     * * @param LoggerInterface $logger ロガー
     * * @param string $model       モデル名 (デフォルト: deepseek-chat)
     * * @param float  $temperature サンプリング温度。DeepSeekは一般的な用途で 0.5 - 0.7 を推奨しています。
     * * @param int    $maxTokens   最大生成トークン数
     * * @param int    $timeout     HTTPリクエストのタイムアウト秒数
     * * @param int    $maxRetries  再試行回数（5xx やネットワークエラー時）
     */
    public function __construct(
        string $apiKey,
        LoggerInterface $logger,
        string $model = 'deepseek-chat',
        float $temperature = 0.5, 
        int $maxTokens = 2048,
        int $timeout = 60,
        int $maxRetries = 3
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->temperature = $temperature;
        $this->maxTokens = $maxTokens;
        $this->logger = $logger;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;

        // Guzzleクライアントの初期化
        // 共通ヘッダー（Authorizationなど）をここで設定しておきます。
        // さらに HandlerStack + Retry ミドルウェアで、5xx や一時的なネットワークエラー時に自動再試行します。

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

                // 5xx は再試行
                if ($response !== null && $response->getStatusCode() >= 500) {
                    $this->logger->warning('DeepSeek retry due to 5xx response', [
                        'status' => $response->getStatusCode(),
                        'retries' => $retries,
                        'uri' => (string)$request->getUri(),
                    ]);
                    return true;
                }

                // タイムアウトや一時的なネットワークエラーも再試行対象
                if ($exception !== null) {
                    $this->logger->warning('DeepSeek retry due to transport exception', [
                        'error' => $exception->getMessage(),
                        'retries' => $retries,
                        'uri' => (string)$request->getUri(),
                    ]);
                    return true;
                }

                return false;
            },
            function (int $retries): int {
                // シンプルな線形バックオフ（ms）
                return 500 * $retries;
            }
        ));

        $this->httpClient = new Client([
            'handler' => $handlerStack,
            'base_uri' => self::BASE_URI,
            'timeout'  => $this->timeout,
            'connect_timeout' => 10,
            'http_errors' => false, // ステータスコード検証は自前で行う
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ]);
    }

    /**
     * チャットリクエストの送信 (ツール呼び出し対応)
     * *
     * * @param array $messages 会話履歴 [['role' => 'user', 'content' => '...'], ...]
     * * @param array $tools    オプション: ツール定義リスト (JSON Schema形式)
     * * @return array          解析後のレスポンス構造 (Content または ToolCalls)
     * * @throws \RuntimeException 通信エラーまたはパースエラー時
     */
    public function chat(array $messages, array $tools = []): array
    {
        // リクエストペイロードの構築
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'stream' => false, // ストリーミングは使用せず、一括レスポンスを受け取ります
        ];

        // ツール（Function Calling）が渡された場合の設定
        // DeepSeekはOpenAI互換の 'tools' パラメータをサポートしています。
        if (!empty($tools)) {
            $payload['tools'] = $tools;
            // 'auto' に設定することで、モデルが文脈に応じてツールを使うか、テキストで返すかを判断します。
            $payload['tool_choice'] = 'auto'; 
        }

        try {
            // デバッグログ: 送信内容の記録
            $this->logger->debug('DeepSeek Request', [
                'model' => $this->model,
                'msg_count' => count($messages),
                'has_tools' => !empty($tools),
                'timeout' => $this->timeout,
                'max_retries' => $this->maxRetries,
            ]);

            // POSTリクエストの送信
            $response = $this->httpClient->post('/chat/completions', [
                'json' => $payload
            ]);

            $statusCode = $response->getStatusCode();
            $rawBody = (string)$response->getBody();

            // ステータスコード検証（4xx/5xx はエラー扱い）
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('DeepSeek API non-success response', [
                    'status' => $statusCode,
                    'body' => mb_substr($rawBody, 0, 1000), // ログサイズを制限
                ]);
                throw new \RuntimeException(sprintf(
                    'DeepSeek API returned non-success status code: %d',
                    $statusCode
                ));
            }

            // レスポンスボディの取得とJSONデコード
            try {
                $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->logger->error('DeepSeek API response parsing failed', [
                    'error' => $e->getMessage(),
                    'body' => mb_substr($rawBody, 0, 1000),
                ]);
                throw new \RuntimeException('DeepSeek API response parsing failed: ' . $e->getMessage(), 0, $e);
            }

            if (!isset($body['choices'][0]['message'])) {
                $this->logger->error('DeepSeek API response missing choices[0].message', [
                    'body' => $body,
                ]);
                throw new \RuntimeException('DeepSeek API response format is unexpected (choices[0].message not found).');
            }

            // メインコンテンツの抽出
            // レスポンス構造: choices[0].message.content または tool_calls
            $choice = $body['choices'][0]['message'] ?? [];
            
            // トークン消費量の記録
            // エンタープライズ利用ではコスト管理やレートリミット監視のために必須です。
            $usage = $body['usage'] ?? [];
            $this->logger->info('DeepSeek Usage', $usage);

            return [
                'role' => $choice['role'] ?? 'assistant',
                'content' => $choice['content'] ?? null,
                // AIがツール使用を要求した場合、ここに呼び出し情報が含まれます
                'tool_calls' => $choice['tool_calls'] ?? null, 
            ];

        } catch (GuzzleException $e) {
            // HTTP通信エラーのハンドリング
            $context = [
                'error' => $e->getMessage(),
            ];

            if (method_exists($e, 'getRequest') && $e->getRequest() !== null) {
                $context['request_uri'] = (string)$e->getRequest()->getUri();
            }
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $context['status'] = $e->getResponse()->getStatusCode();
                $context['response_body'] = mb_substr((string)$e->getResponse()->getBody(), 0, 1000);
            }

            $this->logger->error('DeepSeek API transport error', $context);
            
            // 必要に応じてリトライロジックや、フォールバック（別のLLMへの切り替えなど）を検討する場所です
            throw new \RuntimeException('Failed to communicate with DeepSeek API.', 0, $e);
        }
    }
}