<?php

// src/Application/Actions/Agent/ChatAction.php

namespace App\Application\Actions\Agent;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\AI\Factories\AgentFactory;
use App\Infrastructure\AI\Memory\RedisSessionManager;

/**
 * AIチャットアクション
 * * ユーザーからのメッセージを受け取り、AIエージェントを実行して応答を返すAPIエンドポイントです。
 * * 会話履歴の管理（メモリ）と、エージェントの実行制御を担当します。
 */
class ChatAction
{
    /**
     * コンストラクタ
     * * @param AgentFactory $agentFactory エージェント生成ファクトリ
     * * @param RedisSessionManager $sessionManager 会話履歴（セッション）管理マネージャ
     */
    public function __construct(
        private AgentFactory $agentFactory,
        private RedisSessionManager $sessionManager
    ) {}

    /**
     * アクションの実行
     * * @param Request $request HTTPリクエスト
     * @param Response $response HTTPレスポンス
     * @return Response JSON形式の実行結果
     */
    public function __invoke(Request $request, Response $response): Response
    {
        // 1. 入力データの取得
        $data = $request->getParsedBody();
        $userMessage = $data['message'];
        $sessionId = $data['session_id']; // フロントエンドで生成・管理されるセッションID
        
        // JWT Middlewareなどの認証レイヤーによってセットされたユーザーIDを取得
        // これにより、ユーザー固有のデータ（注文履歴など）に安全にアクセスします。
        $userId = $request->getAttribute('user_id'); 

        // 2. エージェントの生成
        // ファクトリを通じて、このユーザー専用の設定（プロンプトやツール）を持つエージェントを作成します。
        $agent = $this->agentFactory->createRouterAgent($userId);

        // 3. 会話履歴のロード（コンテキストの復元）
        // Redisから過去のやり取りを取得し、エージェントの「短期記憶」としてセットします。
        // これにより、AIは「さっきの話」を覚えている状態で対話できます。
        $history = $this->sessionManager->getHistory($sessionId);
        $agent->setMemory($history);

        // 4. 推論と実行 (ReActループ)
        // ここでAIのメインループが走ります：「思考(Thought)」→「ツールの選択と実行(Action)」→「結果の観察(Observation)」→「回答(Answer)」。
        // 必要な情報が揃うまでツールを自動的に呼び出し、最終的な回答を生成します。
        $result = $agent->reply($userMessage, $sessionId);

        // 5. 新しい会話履歴の保存
        // 今回の「ユーザーの質問」と「AIの最終回答」を履歴に追加します。
        // ※非同期処理にする場合もありますが、ここでは同期的に保存しています。
        $this->sessionManager->addMessage($sessionId, 'user', $userMessage);
        $this->sessionManager->addMessage($sessionId, 'assistant', $result->getContent());

        // 6. 構造化されたレスポンスの構築
        $payload = json_encode([
            'status' => 'success',
            'data' => [
                'reply' => $result->getContent(), // AIからのテキスト回答
                // フロントエンドで「AIが何をしたか（どのツールを使ったか）」を表示するための情報
                // デバッグやユーザーへの透明性確保に役立ちます。
                'tool_calls' => $result->getToolCalls(), 
            ]
        ]);

        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }
}