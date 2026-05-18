<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use App\Common\PerfTrace;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use NeuronAI\Providers\AIProviderInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * DeepSeek LLMプロバイダー
 * * DeepSeek V3 (deepseek-chat) モデルとの通信を実装するクラスです。
 * * DeepSeekのAPIインターフェースはOpenAIと互換性がありますが、Base URLや推奨パラメータが異なります。
 * * 低コストかつ高性能な推論エンジンとして、システムに統合されます。
 */
class DeepSeekProvider implements AIProviderInterface
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
        float $temperature = 0.6,
        int $maxTokens = 4096,
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
            function (int $retries, RequestInterface $request, ?ResponseInterface $response = null, ?GuzzleException $exception = null): bool {
                if ($retries >= $this->maxRetries) {
                    return false;
                }
                return ($response && $response->getStatusCode() >= 500) || $exception !== null;
            },
            fn(int $retries): int => 500 * $retries // linear backoff
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
     * チャットリクエストの送信 (ツール呼び出し対応)
     * *
     * * @param array $messages 会話履歴 [['role' => 'user', 'content' => '...'], ...]
     * * @param array $tools    オプション: ツール定義リスト (JSON Schema形式)
     * * @return array          解析後のレスポンス構造 (Content または ToolCalls)
     * * @throws \RuntimeException 通信エラーまたはパースエラー時
     */
    public function chat(array $messages, array $tools = []): array
    {
        $tApi = PerfTrace::now();
        // リクエストペイロードの構築
        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $this->temperature,
            'max_tokens'  => $this->maxTokens,
            'stream'      => false,
        ];

        // ツール（Function Calling）が渡された場合の設定
        // DeepSeekはOpenAI互換の 'tools' パラメータをサポートしています。
        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        try {
            // デバッグログ: 送信内容の記録
            $this->logger->debug('DeepSeek Chat Request', [
                'model'     => $this->model,
                'messages'  => count($messages),
                'tools'     => count($tools),
            ]);

            // POSTリクエストの送信
            $response = $this->httpClient->post('/chat/completions', ['json' => $payload]);

            // ステータスコード検証（4xx/5xx はエラー扱い）
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $bodySnippet = mb_substr((string)$response->getBody(), 0, 1000);
                $this->logger->error('DeepSeek non-success response', ['status' => $status, 'body' => $bodySnippet]);
                throw new \RuntimeException("DeepSeek API error: HTTP {$status}");
            }

            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($body['choices'][0]['message'])) {
                throw new \RuntimeException('Invalid response: choices[0].message missing');
            }

            $message = $body['choices'][0]['message'];
            $usage = $body['usage'] ?? [];
            if ($usage) {
                $this->logger->info('DeepSeek Token Usage', $usage);
            }

            PerfTrace::log('LLM.DeepSeekChat', $tApi, [
                'model' => $this->model,
                'msg_count' => count($messages),
                'has_tools' => !empty($tools) ? 'yes' : 'no',
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
            ]);

            return [
                'role'       => $message['role'] ?? 'assistant',
                'content'    => $message['content'] ?? null,
                'tool_calls' => $message['tool_calls'] ?? [],
            ];

        } catch (GuzzleException | \JsonException $e) {
            PerfTrace::log('LLM.DeepSeekChat', $tApi, ['status' => 'error', 'error' => $e->getMessage()]);
            $this->logger->error('DeepSeek chat failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('DeepSeek request failed', 0, $e);
        }
    }

    // Optional: Implement streaming if needed (Neuron often expects it)
    public function chatStream(array $messages, array $tools = [], array $options = []): \Generator
    {
        // For now, fallback to non-streaming
        yield $this->chat($messages, $tools);
        // To implement real streaming: set 'stream' => true and parse SSE
    }
}