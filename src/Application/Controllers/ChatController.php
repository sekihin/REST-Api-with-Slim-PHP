<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\AI\Factories\AgentFactory;
use App\Neuron\Agents\GeneralChatAgent;
use Psr\Log\LoggerInterface;

/**
 * チャットコントローラー
 * * クライアントからのチャットリクエスト (POST /api/chat) を処理するエンドポイントです。
 * * ユーザーの入力を受け取り、AIエージェントを実行し、その結果を返します。
 * * また、Redisを使用して会話履歴（コンテキスト）の維持管理も行います。
 */
class ChatController
{
    private AgentFactory $agentFactory;
    private GeneralChatAgent $generalChatAgent;
    private LoggerInterface $logger;

    /**
     * コンストラクタ
     * * @param AgentFactory $agentFactory 設定済みのエージェントを生成するファクトリ
     */
    public function __construct(
        AgentFactory $agentFactory,
        GeneralChatAgent $generalChatAgent,
        LoggerInterface $logger
    )
    {
        $this->agentFactory = $agentFactory;
        $this->generalChatAgent = $generalChatAgent;
        $this->logger = $logger;
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

        // 3. エージェントの生成
        // Factory内部で、LLMの設定や必要なツール (LookupOrder, Refund...) の注入が行われます。
        $agent = $this->agentFactory->createRouterAgent($userId);

        // 5. 推論の実行（AIの思考プロセス + ツール実行）
        try {
            // run() メソッド内で以下のループが自動的に行われます：
            // 思考 -> ツール選択 -> 実行 -> 結果観察 -> 回答生成
            $result = $agent->reply($userMessage, $sessionId);
            $replyContent = $result->getContent();

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

    /**
     * DeepSeek 一般チャット用エンドポイントロジック
     * 元々は Routes.php 内の /agent/deepseek/chat に直接定義されていたものを
     * コントローラークラスへ移動した実装です。
     *
     * 現時点ではルート定義からは切り離されており、必要に応じて
     * ルーティング側からこのメソッドを呼び出す形で再利用できます。
     */
    public function deepseekChat(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $userMessage = trim($data['message'] ?? '');

        if (empty($userMessage)) {
            $response->getBody()->write(json_encode(['error' => 'メッセージを入力してください']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }

        try {
            $result = $this->generalChatAgent->chat($userMessage);
            $reply = $result->content ?? '…ごめん、ちょっとわかんないかも';

            $response->getBody()->write(json_encode([
                'reply'   => $reply,
                'success' => true,
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $this->logger->error('Chat error: ' . $e->getMessage());
            $response->getBody()->write(json_encode(['error' => 'エラーが発生しました']));
            return $response
                ->withStatus(500)
                ->withHeader('Content-Type', 'application/json');
        }
    }
}