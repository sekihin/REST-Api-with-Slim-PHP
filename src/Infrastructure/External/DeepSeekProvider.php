<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Neuron\Providers\LLM\LLMInterface; // Neuronフレームワークで定義されたインターフェースと仮定
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
     */
    public function __construct(
        string $apiKey,
        LoggerInterface $logger,
        string $model = 'deepseek-chat',
        float $temperature = 0.5, 
        int $maxTokens = 2048,
        int $timeout = 60
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->temperature = $temperature;
        $this->maxTokens = $maxTokens;
        $this->logger = $logger;

        // Guzzleクライアントの初期化
        // 共通ヘッダー（Authorizationなど）をここで設定しておきます。
        $this->httpClient = new Client([
            'base_uri' => self::BASE_URI,
            'timeout'  => $timeout,
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
                'has_tools' => !empty($tools)
            ]);

            // POSTリクエストの送信
            $response = $this->httpClient->post('/chat/completions', [
                'json' => $payload
            ]);

            // レスポンスボディの取得とJSONデコード
            $body = json_decode((string)$response->getBody(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('DeepSeek API response parsing failed: ' . json_last_error_msg());
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
            $this->logger->error('DeepSeek API Error: ' . $e->getMessage());
            
            // 必要に応じてリトライロジックや、フォールバック（別のLLMへの切り替えなど）を検討する場所です
            throw new \RuntimeException('Failed to communicate with DeepSeek API.', 0, $e);
        }
    }
}