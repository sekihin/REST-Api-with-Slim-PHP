<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Neuron\Providers\LLM\LLMInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Google Gemini Provider
 * * Google Generative Language API (v1beta) 用のアダプタークラスです。
 * * 役割: アプリケーション内で統一されたOpenAI形式のメッセージ/ツール定義を、
 * * Gemini特有のJSON構造に変換して送信し、レスポンスを再びOpenAI形式に戻します。
 */
class GeminiProvider implements LLMInterface
{
    private Client $httpClient;
    private string $model;
    private LoggerInterface $logger;
    private float $temperature;
    private int $timeout;
    private int $maxRetries;

    // Gemini APIのエンドポイント (v1beta)
    private const BASE_URI = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * コンストラクタ
     * * @param string $apiKey      Google AI Studioで取得したAPIキー
     * * @param LoggerInterface $logger ロガー
     * * @param string $model       モデル名 (推奨: 'gemini-1.5-flash' は高速/安価, 'gemini-1.5-pro' は高性能)
     * * @param float  $temperature 温度 (0.0 - 1.0)
     * * @param int    $timeout     タイムアウト秒数
     * * @param int    $maxRetries  再試行回数（5xx やネットワークエラー時）
     */
    public function __construct(
        string $apiKey,
        LoggerInterface $logger,
        string $model = 'gemini-1.5-flash', 
        float $temperature = 0.5,
        int $timeout = 60,
        int $maxRetries = 3
    ) {
        $this->model = $model;
        $this->logger = $logger;
        $this->temperature = $temperature;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;

        // Guzzleクライアントの初期化
        // Gemini APIは 'Authorization: Bearer' ではなく 'x-goog-api-key' ヘッダーを使用します。
        // DeepSeekProvider 同様、HandlerStack + Retry ミドルウェアを使って一時的なエラーに強くします。

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
                    $this->logger->warning('Gemini retry due to 5xx response', [
                        'status' => $response->getStatusCode(),
                        'retries' => $retries,
                        'uri' => (string)$request->getUri(),
                    ]);
                    return true;
                }

                // タイムアウトや一時的なネットワークエラーも再試行対象
                if ($exception !== null) {
                    $this->logger->warning('Gemini retry due to transport exception', [
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
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $apiKey,
            ],
        ]);
    }

    /**
     * チャットリクエストの送信
     * * OpenAI形式の履歴を受け取り、Gemini形式に変換して送信します。
     */
    public function chat(array $messages, array $tools = []): array
    {
        // 1. システムプロンプトの分離
        // OpenAIは messages 配列に 'role': 'system' を含めますが、
        // Geminiは独立したフィールド `system_instruction` として扱う必要があります。
        $systemInstruction = null;
        $geminiContents = [];
        
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemInstruction = ['parts' => ['text' => $msg['content']]];
            } else {
                // User/Assistant/Tool メッセージの変換
                $geminiContents[] = $this->formatMessage($msg);
            }
        }

        // 2. ツールの変換
        // OpenAI (JSON Schema) -> Gemini (Function Declaration)
        $geminiTools = [];
        if (!empty($tools)) {
            $geminiTools = $this->formatTools($tools);
        }

        // 3. リクエストペイロードの構築
        $payload = [
            'contents' => $geminiContents,
            'generationConfig' => [
                'temperature' => $this->temperature,
                // Geminiには max_tokens パラメータは必須ではありません（強制的に切りたい場合のみ指定）
            ]
        ];

        // システムプロンプトがあれば追加
        if ($systemInstruction) {
            $payload['system_instruction'] = $systemInstruction;
        }

        // ツールがあれば追加 (Geminiは tools 配列の中に function_declarations をラップする構造)
        if (!empty($geminiTools)) {
            $payload['tools'] = [$geminiTools]; 
        }

        try {
            $this->logger->debug('Gemini Request Payload', [
                'model' => $this->model,
                'payload' => json_encode($payload),
                'timeout' => $this->timeout,
                'max_retries' => $this->maxRetries,
            ]);

            // 4. APIリクエスト送信
            // エンドポイント形式: models/{model}:generateContent
            $response = $this->httpClient->post("{$this->model}:generateContent", [
                'json' => $payload
            ]);

            $statusCode = $response->getStatusCode();
            $rawBody = (string)$response->getBody();

            // ステータスコード検証（4xx/5xx はエラー扱い）
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('Gemini API non-success response', [
                    'status' => $statusCode,
                    'body' => mb_substr($rawBody, 0, 1000), // ログサイズを制限
                ]);
                throw new \RuntimeException(sprintf(
                    'Gemini API returned non-success status code: %d',
                    $statusCode
                ));
            }

            // レスポンスボディの取得とJSONデコード
            try {
                $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->logger->error('Gemini API response parsing failed', [
                    'error' => $e->getMessage(),
                    'body' => mb_substr($rawBody, 0, 1000),
                ]);
                throw new \RuntimeException('Gemini API response parsing failed: ' . $e->getMessage(), 0, $e);
            }
            
            // 5. レスポンスの解析とOpenAI形式への逆変換
            return $this->parseResponse($body);

        } catch (GuzzleException $e) {
            // Googleの詳細なエラー情報を取得してログに残す
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

            $this->logger->error('Gemini API transport error', $context);
            throw new \RuntimeException('Gemini API request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * メッセージ形式の変換 (OpenAI -> Gemini)
     * * 役割 (role) や コンテンツ (parts) の構造を変換します。
     */
    private function formatMessage(array $msg): array
    {
        // Roleのマッピング: OpenAI 'assistant' -> Gemini 'model'
        $role = ($msg['role'] === 'assistant') ? 'model' : 'user';
        $parts = [];

        // テキストコンテンツの処理
        if (!empty($msg['content'])) {
            $parts[] = ['text' => $msg['content']];
        }

        // ツール呼び出し要求 (Tool Call) の処理
        // OpenAI 'tool_calls' -> Gemini 'functionCall'
        if (!empty($msg['tool_calls'])) {
            foreach ($msg['tool_calls'] as $call) {
                $parts[] = [
                    'functionCall' => [
                        'name' => $call['function']['name'],
                        'args' => json_decode($call['function']['arguments'], true)
                    ]
                ];
            }
        }

        // ツール実行結果 (Tool Response) の処理
        // OpenAI 'tool' role -> Gemini 'functionResponse'
        if ($msg['role'] === 'tool') {
            // Gemini REST APIでは、ツールの実行結果は 'user' ロールの一部として送信します。
            $role = 'user'; 
            $parts = [[
                'functionResponse' => [
                    'name' => $msg['name'], // どの関数の結果かを指定
                    'response' => [
                        'name' => $msg['name'],
                        // Geminiのargs/contentはオブジェクトである必要があります
                        'content' => json_decode($msg['content'], true) 
                    ]
                ]
            ]];
        }

        return [
            'role' => $role,
            'parts' => $parts
        ];
    }

    /**
     * ツール定義の変換 (OpenAI Schema -> Gemini Declaration)
     */
    private function formatTools(array $tools): array
    {
        $declarations = [];
        foreach ($tools as $tool) {
            if ($tool['type'] !== 'function') continue;
            
            $func = $tool['function'];
            $declarations[] = [
                'name' => $func['name'],
                'description' => $func['description'] ?? '',
                // Geminiは標準的なJSON Schemaをサポートしているため、parametersはそのまま流用可能
                'parameters' => $func['parameters'] 
            ];
        }
        
        return ['function_declarations' => $declarations];
    }

    /**
     * レスポンスの解析 (Gemini -> OpenAI)
     * * アプリケーション側がOpenAI形式を期待しているため、構造を合わせます。
     */
    private function parseResponse(array $body): array
    {
        $candidate = $body['candidates'][0] ?? [];
        $content = $candidate['content'] ?? [];
        $parts = $content['parts'] ?? [];

        $result = [
            'role' => 'assistant',
            'content' => null, // テキスト結合用
            'tool_calls' => [] // ツール呼び出し格納用
        ];

        foreach ($parts as $part) {
            // テキスト部分の抽出
            if (isset($part['text'])) {
                $result['content'] .= $part['text'];
            }

            // 関数呼び出し部分の抽出
            if (isset($part['functionCall'])) {
                $result['tool_calls'][] = [
                    // OpenAI形式には一意なIDが必要ですが、GeminiはIDを返さないためダミーを生成します
                    'id' => 'call_' . uniqid(), 
                    'type' => 'function',
                    'function' => [
                        'name' => $part['functionCall']['name'],
                        // 引数をJSON文字列に戻す
                        'arguments' => json_encode($part['functionCall']['args'])
                    ]
                ];
            }
        }

        // 安全フィルター (Safety Settings) 等でブロックされた場合の処理
        // コンテンツもツール呼び出しも空の場合、ブロックされた可能性が高いです。
        if (empty($result['content']) && empty($result['tool_calls'])) {
            $finishReason = $candidate['finishReason'] ?? 'UNKNOWN';
            $this->logger->warning("Gemini returned empty content. Finish Reason: {$finishReason}");
            $result['content'] = "[System: Geminiの安全ポリシーにより応答がブロックされました (理由: {$finishReason})]";
        }

        return $result;
    }
}