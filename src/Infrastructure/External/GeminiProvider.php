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
 * Google Gemini 統合プロバイダー
 *
 * - LLMチャット（function calling / tool対応）
 * - Text Embeddings 生成
 *
 * NeuronAIのAIProviderInterfaceを実装しつつ、RAG用途のembeddingsも提供。
 * 公式Geminiプロバイダーが要件を満たさない場合のカスタム実装として使用。
 */
class GeminiProvider implements AIProviderInterface
{
    private Client $httpClient;
    private string $apiKey;
    private string $chatModel;
    private float $temperature;
    private int $timeout;
    private int $maxRetries;
    private LoggerInterface $logger;

    private const CHAT_BASE_URI    = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const EMBEDDING_PATH   = '/v1beta/models/embedding-001:embedContent';
    private const EMBEDDING_DIM    = 768; // embedding-001 の次元数（2026年現在）

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
        string $chatModel = 'gemini-1.5-flash',
        float $temperature = 0.5,
        int $timeout = 60,
        int $maxRetries = 3
    ) {
        $this->apiKey     = $apiKey;
        $this->chatModel  = $chatModel;
        $this->logger     = $logger;
        $this->temperature = $temperature;
        $this->timeout    = $timeout;
        $this->maxRetries = $maxRetries;

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
            'timeout'         => $this->timeout,
            'connect_timeout' => 10,
            'http_errors'     => false,
            'headers'         => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    // ── AIProviderInterface 実装（チャット部分） ───────────────────────────────────────

    /**
     * チャットリクエストの送信
     * * OpenAI形式の履歴を受け取り、Gemini形式に変換して送信します。
     */
    public function chat(array $messages, array $tools = []): array
    {
        $tApi = PerfTrace::now();
        // 1. システムプロンプトの分離
        // OpenAIは messages 配列に 'role': 'system' を含めますが、
        // Geminiは独立したフィールド `system_instruction` として扱う必要があります。
        $systemInstruction = null;
        $geminiContents = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemInstruction = ['parts' => ['text' => $msg['content']]];
            } else {
                $geminiContents[] = $this->formatMessage($msg);
            }
        }

        // 2. ツールの変換
        // OpenAI (JSON Schema) -> Gemini (Function Declaration)
        $geminiTools = $this->formatTools($tools);

        // 3. リクエストペイロードの構築
        $payload = [
            'contents' => $geminiContents,
            'generationConfig' => [
                'temperature' => $this->temperature,
        // Geminiには max_tokens パラメータは必須ではありません（強制的に切りたい場合のみ指定）
            ],
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

            // 4. APIリクエスト送信
            // エンドポイント形式: models/{model}:generateContent
            $response = $this->httpClient->post(self::CHAT_BASE_URI . "{$this->chatModel}:generateContent", [
                'headers' => ['x-goog-api-key' => $this->apiKey],
                'json'    => $payload,
            ]);

        // レスポンスボディの取得とJSONデコード
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            $parsed = $this->parseResponse($body);
            PerfTrace::log('LLM.GeminiChat', $tApi, [
                'model' => $this->chatModel,
                'msg_count' => count($messages),
                'has_tools' => !empty($tools) ? 'yes' : 'no',
            ]);
            return $parsed;
        } catch (GuzzleException | \JsonException $e) {
            PerfTrace::log('LLM.GeminiChat', $tApi, ['status' => 'error', 'error' => $e->getMessage()]);
        // Googleの詳細なエラー情報を取得してログに残す
            $this->logger->error('Gemini chat request failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Gemini chat failed', 0, $e);
        }
    }

    /**
     * メッセージ形式の変換 (OpenAI -> Gemini)
     * * 役割 (role) や コンテンツ (parts) の構造を変換します。
     */
    private function formatMessage(array $msg): array
    {
        // Roleのマッピング: OpenAI 'assistant' -> Gemini 'model'
        $role = $msg['role'] === 'assistant' ? 'model' : 'user';
        $parts = [];

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
                        'args' => json_decode($call['function']['arguments'], true) ?? [
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
                    'name' => $msg['name'] ?? 'tool',  // どの関数の結果かを指定
                    'response' => [
                        'name' => $msg['name'] ?? 'tool',
                        // Geminiのargs/contentはオブジェクトである必要があります
                        'content' => json_decode($msg['content'] ?? '{}', true) ?? []
                    ]
                ]
            ]];
        }

        return ['role' => $role, 'parts' => $parts];
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
                'name'        => $func['name'],
                'description' => $func['description'] ?? '',
                // Geminiは標準的なJSON Schemaをサポートしているため、parametersはそのまま流用可能
                'parameters'  => $func['parameters'] ?? ['type' => 'object', 'properties' => []]
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
        $parts = $candidate['content']['parts'] ?? [];

        $result = [
            'role'       => 'assistant',
            'content'    => null, // テキスト結合用
            'tool_calls' => [], // ツール呼び出し格納用
        ];

        foreach ($parts as $part) {
             // テキスト部分の抽出
            if (isset($part['text'])) {
                $result['content'] .= ($result['content'] ? ' ' : '') . $part['text'];
            }
            // 関数呼び出し部分の抽出
            if (isset($part['functionCall'])) {
                $result['tool_calls'][] = [
                    // OpenAI形式には一意なIDが必要ですが、GeminiはIDを返さないためダミーを生成します
                    'id'       => 'call_' . uniqid(),
                    'type'     => 'function',
                    'function' => [
                        'name'      => $part['functionCall']['name'],
                        // 引数をJSON文字列に戻す
                        'arguments' => json_encode($part['functionCall']['args'] ?? [])
                    ]
                ];
            }
        }

        // 安全フィルター (Safety Settings) 等でブロックされた場合の処理
        // コンテンツもツール呼び出しも空の場合、ブロックされた可能性が高いです。
        if (empty($result['content']) && empty($result['tool_calls'])) {
            $reason = $candidate['finishReason'] ?? 'UNKNOWN';
            $result['content'] = "[Gemini safety block: {$reason}]";
            $this->logger->warning("Gemini response blocked", ['reason' => $reason]);
        }

        return $result;
    }

    // ── Embeddings 機能（RAG用） ──────────────────────────────────────────────────────

    /**
     * テキストをGemini embeddingモデルでベクトル化
     * @param string $text
     * @return array<float> 768次元ベクトル（失敗時はゼロ埋め）
     */
    public function getEmbedding(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return array_fill(0, self::EMBEDDING_DIM, 0.0);
        }

        try {
            $payload = [
                'model'   => 'models/embedding-001',
                'content' => ['parts' => [['text' => $text]]],
            ];

            $response = $this->httpClient->post(self::EMBEDDING_PATH, [
                'query'   => ['key' => $this->apiKey],
                'json'    => $payload,
            ]);

            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            $embedding = $data['embedding']['values'] ?? [];
            $len = count($embedding);

            if ($len === self::EMBEDDING_DIM) {
                return $embedding;
            }

            // 次元調整（稀だが安全策）
            if ($len > self::EMBEDDING_DIM) {
                return array_slice($embedding, 0, self::EMBEDDING_DIM);
            }
            return array_merge($embedding, array_fill(0, self::EMBEDDING_DIM - $len, 0.0));

        } catch (\Throwable $e) {
            $this->logger->error('Gemini embedding failed', ['error' => $e->getMessage(), 'text_length' => strlen($text)]);
            return array_fill(0, self::EMBEDDING_DIM, 0.0);
        }
    }
}