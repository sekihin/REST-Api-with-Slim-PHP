<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\RequestInterface as Request; 
use Psr\Http\Message\ResponseInterface as Response;
use App\Application\Controllers\ChatController;
use App\Application\Controllers\OrderController; 
use App\Application\Controllers\InventoryController; 
use App\Application\Controllers\AuthController;
use App\App\CustomResponse;
use App\Infrastructure\AI\Factories\AgentFactory;
use App\Domain\Knowledge\KnowledgeBaseService;
/**
 * アプリケーションルート定義
 * * Slimアプリインスタンスを受け取り、各ルートを登録するクロージャを返します。
 * * グローバルミドルウェア（CORS, BodyParsingなど）は別途 App.php 等で適用されている前提です。
 */
return function (App $app) {

    // Add test route in Routes.php
    // GET /agent/test と POST /agent/test の両方を受け付ける
    $app->map(['GET', 'POST'], '/agent/test', function (Request $request, Response $response) {
        // CustomResponseを使用してJSONレスポンスを返す
        $customResponse = new CustomResponse();
        return $customResponse->withJson(['status' => 'ok'], 200, JSON_UNESCAPED_UNICODE);
    });

    // メッセージを受け取るテスト用チャットエンドポイント
    // URL 例:
    //   GET /agent/chattest/?message=こんにちは
    //   POST /agent/chattest/  body: { "message": "こんにちは" } または message=...
    $app->map(['GET', 'POST'], '/agent/chattest', function (Request $request, Response $response) {
        $customResponse = new CustomResponse();

        $queryParams = $request->getQueryParams();
        $bodyParams  = $request->getParsedBody() ?? [];

        $message =
            $bodyParams['message']
                ?? $queryParams['message']
                ?? null;
        if ($message === null) {
            return $customResponse->withJson(['status' => 'error', 'message' => 'No message provided'], 400);
        } else {
            $response_message = "You said: " . $message;
            return $customResponse->withJson(
                [
                    'status'  => 'ok',
                    'message' => $response_message,
                ],
                200,
                JSON_UNESCAPED_UNICODE
            );
        }
    });

    // メッセージを受け取るテスト用チャットエンドポイント
    // URL 例:
    //   GET /agent/doubao/step1/?message=こんにちは
    //   POST /agent/doubao/step1  body: { "message": "こんにちは" } または message=...
    $app->map(['GET', 'POST'], '/agent/doubao/step1', function (Request $request, Response $response) {
        $customResponse = new CustomResponse();

        $queryParams = $request->getQueryParams();
        $bodyParams  = $request->getParsedBody() ?? [];

        $message =
            $bodyParams['message']
                ?? $queryParams['message']
                ?? null;
        if ($message === null) {
            return $customResponse->withJson(['status' => 'error', 'message' => 'No message provided'], 400);
        } else {

		// 豆包APIキーを設定
		$apiKey = getenv('DOBAO_API_KEY') ?: throw new \Exception('Missing DOBAO_API_KEY for embedding');
		$logger = new \Psr\Log\NullLogger();

		// 豆包プロバイダーの初期化
		$doubao = new \App\Infrastructure\External\DoubaoProvider(
		    apiKey: $apiKey,
		    logger: $logger,
		    // モデルは DoubaoProvider 側のデフォルト ('doubao-1-5-pro-32k-250115') を使用
		    temperature: 0.5
		);

		// リクエスト送信
		$messages = [
		    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
		    ['role' => 'user', 'content' => $message]
		];

        $response_message = '';
		try {
		    $response = $doubao->chat($messages);
		    $response_message = $response['content']; // 豆包の回答を出力
		} catch (\RuntimeException $e) {
		    $response_message = 'エラー: ' . $e->getMessage();
		}

            return $customResponse->withJson(
                [
                    'status'  => 'ok',
                    'message' => $response_message,
                ],
                200,
                JSON_UNESCAPED_UNICODE
            );
        }
    });

    // メッセージを受け取るテスト用チャットエンドポイント
    // URL 例:
    //   GET /agent/doubao/step2/?message=こんにちは
    //   POST /agent/doubao/step2  body: { "message": "こんにちは" } または message=...
    // RAG Workflow: User Message -> Pre-Process Node -> RouterAgent
    $app->map(['GET', 'POST'], '/agent/doubao/step2', function (Request $request, Response $response) use ($app) {
        $customResponse = new CustomResponse();

        $queryParams = $request->getQueryParams();
        $bodyParams  = $request->getParsedBody() ?? [];

        $userMessage =
            $bodyParams['message']
                ?? $queryParams['message']
                ?? null;
        if ($userMessage === null || $userMessage === '') {
            return $customResponse->withJson(['status' => 'error', 'message' => 'No message provided'], 400);
        }

        // --- Pre-Process Node (RAG 用クエリ正規化) ---
        // トリム・連続空白の正規化。必要に応じてクエリ書き換えや意図の正規化をここに追加可能。
        $preprocessedMessage = trim(preg_replace('/\s+/u', ' ', (string) $userMessage));
        if ($preprocessedMessage === '') {
            return $customResponse->withJson(['status' => 'error', 'message' => 'Message is empty after preprocessing'], 400);
        }

        // --- RouterAgent で実行 ---
        try {
            $container = $app->getContainer();
            /** @var AgentFactory $agentFactory */
            $agentFactory = $container->get(AgentFactory::class);
            $agent = $agentFactory->createRouterAgent('guest');
            $result = $agent->run($preprocessedMessage);
            $replyContent = $result->getContent();
        } catch (\Throwable $e) {
            return $customResponse->withJson([
                'status'  => 'error',
                'message' => 'エラー: ' . $e->getMessage(),
            ], 500, JSON_UNESCAPED_UNICODE);
        }

        return $customResponse->withJson(
            [
                'status'   => 'ok',
                'reply'    => $replyContent,
                'preprocessed_message' => $preprocessedMessage, // デバッグ用
            ],
            200,
            JSON_UNESCAPED_UNICODE
        );
    });      

        // メッセージを受け取るテスト用チャットエンドポイント
    // URL 例:
    //   GET /agent/doubao/step3/?message=こんにちは
    //   POST /agent/doubao/step3  body: { "message": "こんにちは" } または message=...
    // RAG Workflow:
    //   1. User Message
    //   2. Pre-Process Node (クエリ正規化)
    //   3. Retrieval Node (KnowledgeBaseService でベクトル検索)
    //   4. Post-Process Node (検索結果のテキスト整形)
    //   5. Enrich Instructions Node (RouterAgent のシステムプロンプトへ注入)
    $app->map(['GET', 'POST'], '/agent/doubao/step3', function (Request $request, Response $response) use ($app) {
        $customResponse = new CustomResponse();

        $queryParams = $request->getQueryParams();
        $bodyParams  = $request->getParsedBody() ?? [];

        $userMessage =
            $bodyParams['message']
                ?? $queryParams['message']
                ?? null;
        if ($userMessage === null || $userMessage === '') {
            return $customResponse->withJson(['status' => 'error', 'message' => 'No message provided'], 400);
        }

        // --- Pre-Process Node (RAG 用クエリ正規化) ---
        // トリム・連続空白の正規化。必要に応じてクエリ書き換えや意図の正規化をここに追加可能。
        $preprocessedMessage = trim(preg_replace('/\s+/u', ' ', (string) $userMessage));
        if ($preprocessedMessage === '') {
            return $customResponse->withJson(['status' => 'error', 'message' => 'Message is empty after preprocessing'], 400);
        }

        // --- Retrieval Node: ナレッジベース検索 ---
        $knowledgeContext = '';
        try {
            $container = $app->getContainer();
            /** @var KnowledgeBaseService $kbService */
            $kbService = $container->get(KnowledgeBaseService::class);
            $kbResults = $kbService->searchDocuments($preprocessedMessage, 3);

            // --- Post-Process Node: 検索結果のテキスト整形 ---
            if (!empty($kbResults)) {
                $lines = [];
                $lines[] = "以下は社内ナレッジベースから取得した関連情報です。内容を参考にして、ユーザーの質問に一貫性のある回答を行ってください。";
                foreach ($kbResults as $idx => $doc) {
                    $rank = $idx + 1;
                    $title = $doc['title'] ?? '不明';
                    $section = $doc['section'] ?? '不明';
                    $content = $doc['content'] ?? '';
                    $lines[] = "[文書 {$rank}: {$title} ({$section})]";
                    $lines[] = $content;
                    $lines[] = "";
                }
                $knowledgeContext = implode("\n", $lines);
            }
        } catch (\Throwable $e) {
            // ナレッジ検索部分のエラーは致命的ではないため、ログ出力のみにとどめて通常のフローを継続
            // （本番では LoggerInterface を使って記録することを推奨）
        }

        // --- RouterAgent で実行 (Enrich Instructions Node を含む) ---
        try {
            $container = $app->getContainer();
            /** @var AgentFactory $agentFactory */
            $agentFactory = $container->get(AgentFactory::class);
            $agent = $agentFactory->createRouterAgent('guest');

            if ($knowledgeContext !== '') {
                $agent->withKnowledgeContext($knowledgeContext);
            }

            $result = $agent->run($preprocessedMessage);
            $replyContent = $result->getContent();
        } catch (\Throwable $e) {
            return $customResponse->withJson([
                'status'  => 'error',
                'message' => 'エラー: ' . $e->getMessage(),
            ], 500, JSON_UNESCAPED_UNICODE);
        }

        return $customResponse->withJson(
            [
                'status'   => 'ok',
                'reply'    => $replyContent,
                'preprocessed_message' => $preprocessedMessage, // デバッグ用
                'knowledge_used' => $knowledgeContext !== '',
            ],
            200,
            JSON_UNESCAPED_UNICODE
        );
    }); 

    // シンプルなPOST /chat エンドポイント（JSONで会話）
    $app->post('/agent/deepseek', function (Request $request, Response $response) {
        $data = $request->getParsedBody();
        $userMessage = trim($data['message'] ?? '');

        if (empty($userMessage)) {
            $response->getBody()->write(json_encode(['error' => 'メッセージを入力してください']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        /** @var GeneralChatAgent $agent */
        $agent = $this->get(GeneralChatAgent::class);

        try {
            $result = $agent->chat($userMessage);  // Neuron AIのchat()メソッド（文字列入力対応版）

            $reply = $result->content ?? '…ごめん、ちょっとわかんないかも';

            $response->getBody()->write(json_encode([
                'reply' => $reply,
                'success' => true
            ]));

        } catch (\Throwable $e) {
            $this->get('logger')->error('Chat error: ' . $e->getMessage());
            $response->getBody()->write(json_encode(['error' => 'エラーが発生しました']));
            return $response->withStatus(500);
        }

        return $response->withHeader('Content-Type', 'application/json');
    });

    // APIルートグループ
    // 全てのルートは '/api' プレフィックスを持ちます。
    $app->group('/api', function (RouteCollectorProxy $group) {
        
        // --- 🤖 AIエージェント機能 ---
        // ユーザーからのチャットメッセージを受け取り、AIの回答を返します。
        // URL: POST /api/chat
        $group->post('/chat', [ChatController::class, 'chat']);

        // --- 📦 ドメイン: 在庫管理 (Inventory) ---
        // 在庫に関する操作を '/inventory' グループにまとめます。
        $group->group('/inventory', function (RouteCollectorProxy $inventory) {
            
            // 在庫検索
            // URL: GET /api/inventory/search?name=xxx
            // 商品名などをクエリパラメータで受け取り、在庫状況を返します。
            // AIツールの `CheckInventoryTool` と同様の検索ロジックを使用します。
            $inventory->get('/search', [InventoryController::class, 'search']);
            
            // 特定SKUの在庫詳細取得 (RESTfulスタイル)
            // URL: GET /api/inventory/{sku}
            // $inventory->get('/{sku}', [InventoryController::class, 'get']);
        });

        // --- 🛒 ドメイン: 注文管理 (Orders) ---
        // 注文に関する操作を '/orders' グループにまとめます。
        $group->group('/orders', function (RouteCollectorProxy $orders) {
            
            // 注文詳細の取得
            // URL: GET /api/orders/{id}
            // {id} は注文ID (例: ORD-2023-001) に置き換わります。
            $orders->get('/{id}', [OrderController::class, 'get']);

            // 返金申請処理
            // URL: POST /api/orders/{id}/refund
            // AIツールの `RefundOrderTool` と対になるAPIエンドポイントです。
            // 管理画面やマイページから、人間がボタンを押して返金する際に使用されます。
            $orders->post('/{id}/refund', [OrderController::class, 'refund']);
            
            // 注文作成 (例)
            // URL: POST /api/orders
            // $orders->post('', [OrderController::class, 'create']);
        });

    });

    // ... その他のルート (ヘルスチェック, OPTIONS, 404ハンドリングなど) ...

    // 通常のルート定義
    $app->map(['GET', 'POST'], '/agent/getAccessToken', [AuthController::class, 'getAccessToken']);

};