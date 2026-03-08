<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\AI\Factories\AgentFactory;
use App\Infrastructure\AI\Memory\RedisMessageHistory;
use Redis;

/**
 * チャットコントローラー
 * * クライアントからのチャットリクエスト (POST /api/chat) を処理するエンドポイントです。
 * * ユーザーの入力を受け取り、AIエージェントを実行し、その結果を返します。
 * * また、Redisを使用して会話履歴（コンテキスト）の維持管理も行います。
 */
class ChatController
{
    private AgentFactory $agentFactory;
    private Redis $redis;

    /**
     * コンストラクタ
     * * @param AgentFactory $agentFactory 設定済みのエージェントを生成するファクトリ
     * * @param Redis        $redis        会話履歴を保存するためのRedisインスタンス
     */
    public function __construct(AgentFactory $agentFactory, Redis $redis)
    {
        $this->agentFactory = $agentFactory;
        $this->redis = $redis;
    }

    /**
     * 対話リクエストの処理
     * * POST /api/chat
     * *
     * * @param Request  $request  HTTPリクエスト
     * * @param Response $response HTTPレスポンス
     * * @return Response JSON形式の実行結果
     */
    public function chat(Request $request, Response $response): Response
    {
        // 1. 入力とコンテキストの取得
        $body = $request->getParsedBody();
        $userMessage = $body['message'] ?? '';
        
        // 認証ミドルウェア (AuthMiddleware) 経由で設定された現在のユーザーIDを取得すると仮定
        // 未ログインの場合は 'guest' とする（要件に応じてエラーにすべき箇所）
        $userId = $request->getAttribute('user_id', 'guest'); 
        
        // セッションIDの生成または取得
        // フロントエンドから渡される場合はそれを使用し、なければバックエンドで生成します。
        // 例: 'chat_user_1001'
        $sessionId = $body['session_id'] ?? "chat_{$userId}";

        // バリデーション: メッセージが空の場合はエラーを返す
        if (empty($userMessage)) {
            $response->getBody()->write(json_encode(['error' => 'Message cannot be empty']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // 2. 会話履歴（メモリ）の初期化
        // RedisMessageHistoryを手動でインスタンス化します。
        // ※より複雑になる場合は、HistoryFactoryなどを用意してカプセル化することを推奨します。
        $history = new RedisMessageHistory($this->redis, $sessionId);

        // 3. エージェントの生成
        // Factory内部で、LLMの設定や必要なツール (LookupOrder, Refund...) の注入が行われます。
        $agent = $this->agentFactory->createRouterAgent($userId);

        // 4. 短期記憶（スライディングウィンドウ）のロード
        // Redisから直近N件の履歴を取得し、エージェントに注入します。
        // これにより、AIは文脈（コンテキスト）を理解して回答できるようになります。
        $contextMessages = $history->getMessages();
        $agent->addToChatHistory($contextMessages);

        // 5. 推論の実行（AIの思考プロセス + ツール実行）
        try {
            // run() メソッド内で以下のループが自動的に行われます：
            // 思考 -> ツール選択 -> 実行 -> 結果観察 -> 回答生成
            $result = $agent->run($userMessage);
            $replyContent = $result->getContent();

            // 6. 新しい会話履歴の永続化
            // 次回の会話のために、今回の「ユーザーの質問」と「AIの回答」を保存します。
            $history->addUserMessage($userMessage);
            $history->addAssistantMessage($replyContent);

            // 7. レスポンスの返却
            $payload = json_encode([
                'status' => 'success',
                'data' => [
                    'reply' => $replyContent,
                    'session_id' => $sessionId,
                    // オプション: デバッグ用にトークン使用量などを返すことも可能
                    // 'usage' => $result->getUsage() 
                ]
            ], JSON_UNESCAPED_UNICODE);

            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Throwable $e) {
            // エラーハンドリング
            // 本番環境では詳細なエラーログをサーバー側に記録し、
            // クライアントには詳細を見せず、当たり障りのないメッセージを返します。
            // error_log($e->getMessage());
            
            $payload = json_encode([
                'status' => 'error', 
                'message' => 'AIサービスが混み合っています。しばらく経ってからもう一度お試しください。' // AI 服务暂时繁忙...
            ], JSON_UNESCAPED_UNICODE);
            
            $response->getBody()->write($payload);
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}