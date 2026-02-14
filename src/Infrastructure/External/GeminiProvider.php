<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Neuron\Providers\LLM\LLMInterface;
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

    // Gemini APIのエンドポイント (v1beta)
    private const BASE_URI = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * コンストラクタ
     * * @param string $apiKey      Google AI Studioで取得したAPIキー
     * * @param LoggerInterface $logger ロガー
     * * @param string $model       モデル名 (推奨: 'gemini-1.5-flash' は高速/安価, 'gemini-1.5-pro' は高性能)
     * * @param float  $temperature 温度 (0.0 - 1.0)
     * * @param int    $timeout     タイムアウト秒数
     */
    public function __construct(
        string $apiKey,
        LoggerInterface $logger,
        string $model = 'gemini-1.5-flash', 
        float $temperature = 0.5,
        int $timeout = 60
    ) {
        $this->model = $model;
        $this->logger = $logger;
        $this->temperature = $temperature;

        // Guzzleクライアントの初期化
        // Gemini APIは 'Authorization: Bearer' ではなく 'x-goog-api-key' ヘッダーを使用します。
        $this->httpClient = new Client([
            'base_uri' => self::BASE_URI,
            'timeout'  => $timeout,
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
            $this->logger->debug('Gemini Request Payload', ['model' => $this->model, 'payload' => json_encode($payload)]);

            // 4. APIリクエスト送信
            // エンドポイント形式: models/{model}:generateContent
            $response = $this->httpClient->post("{$this->model}:generateContent", [
                'json' => $payload
            ]);

            $body = json_decode((string)$response->getBody(), true);
            
            // 5. レスポンスの解析とOpenAI形式への逆変換
            return $this->parseResponse($body);

        } catch (GuzzleException $e) {
            // Googleの詳細なエラー情報を取得してログに残す
            $errorBody = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
            $this->logger->error('Gemini API Error', ['msg' => $e->getMessage(), 'body' => $errorBody]);
            throw new \RuntimeException('Gemini API request failed: ' . $e->getMessage());
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