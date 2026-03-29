<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\RequestInterface as Request; 
use Psr\Http\Message\ResponseInterface as Response;
use App\Application\Controllers\ChatController;
use App\Application\Controllers\RagController;
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

    // APIルートグループ
    // 全てのルートは '/agent' プレフィックスを持ちます。
    $app->group('/agent', function (RouteCollectorProxy $group) use ($app) {

        // GET /agent/test と POST /agent/test の両方を受け付ける
        $group->map(['GET', 'POST'], '/test', function (Request $request, Response $response) {
            $customResponse = new CustomResponse();
            return $customResponse->withJson(['status' => 'ok'], 200, JSON_UNESCAPED_UNICODE);
        });

        // メッセージを受け取るテスト用チャットエンドポイント
        // URL 例:
        //   GET /agent/test_chat/?message=こんにちは
        //   POST /agent/test_chat/  body: { "message": "こんにちは" } または message=...
        $group->map(['GET', 'POST'], '/test_chat', function (Request $request, Response $response) {
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
        //   GET /agent/route?message=こんにちは
        //   POST /agent/route  body: { "message": "こんにちは" } または message=...
        // RAG Workflow: User Message -> Pre-Process Node -> RouterAgent
        $group->map(['GET', 'POST'], '/route', function (Request $request, Response $response) use ($app) {
            $customResponse = new CustomResponse();

            $queryParams = $request->getQueryParams();
            $bodyParams  = $request->getParsedBody() ?? [];

            // 1. User Message: リクエストからユーザーメッセージを取得
            $userMessage =
                $bodyParams['message']
                    ?? $queryParams['message']
                    ?? null;
            if ($userMessage === null || $userMessage === '') {
                return $customResponse->withJson(['status' => 'error', 'message' => 'No message provided'], 400);
            }

            // 2. Pre-Process Node (RAG 用クエリ正規化)
            //    トリム・連続空白の正規化。必要に応じてクエリ書き換えや意図の正規化をここに追加可能。
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

        // JWT アクセストークン取得
        // URL: GET /agent/getAccessToken または POST /agent/getAccessToken
        $group->map(['GET', 'POST'], '/getAccessToken', [AuthController::class, 'getAccessToken']);

        // --- 🤖 AIエージェント機能 ---
        // ユーザーからのチャットメッセージを受け取り、AIの回答を返します。
        // URL: POST /agent/chat
        $group->post('/chat', [ChatController::class, 'chat']);

        // --- 🤖 RAGエージェント機能 ---
        // ユーザーからのRAGリクエストを受け取り、RAGの回答を返します。
        // URL: GET /agent/rag?message=... または POST /agent/rag (body: message)
        $group->map(['GET', 'POST'], '/rag', [RagController::class, 'rag']);

        // --- 📦 ドメイン: 在庫管理 (Inventory) ---
        // 在庫に関する操作を '/inventory' グループにまとめます。
        $group->group('/inventory', function (RouteCollectorProxy $inventory) {
            
            // 在庫検索
            // URL: GET /agent/inventory/search?name=xxx
            // 商品名などをクエリパラメータで受け取り、在庫状況を返します。
            // AIツールの `CheckInventoryTool` と同様の検索ロジックを使用します。
            $inventory->get('/search', [InventoryController::class, 'search']);
            
            // 特定SKUの在庫詳細取得 (RESTfulスタイル)
            // URL: GET /agent/inventory/{sku}
            // $inventory->get('/{sku}', [InventoryController::class, 'get']);
        });

        // --- 🛒 ドメイン: 契約管理 (Orders) ---
        // 契約に関する操作を '/orders' グループにまとめます。
        $group->group('/orders', function (RouteCollectorProxy $orders) {
            
            // 契約詳細の取得
            // URL: GET /agent/orders/{id}
            // {id} は契約ID (例: ORD-2023-001) に置き換わります。
            $orders->get('/{id}', [OrderController::class, 'get']);

            // 返金申請処理
            // URL: POST /agent/orders/{id}/refund
            // AIツールの `RefundOrderTool` と対になるAPIエンドポイントです。
            // 管理画面やマイページから、人間がボタンを押して返金する際に使用されます。
            $orders->post('/{id}/refund', [OrderController::class, 'refund']);
            
            // 契約作成 (例)
            // URL: POST /agent/orders
            // $orders->post('', [OrderController::class, 'create']);
        });

    });

    // ... その他のルート (ヘルスチェック, OPTIONS, 404ハンドリングなど) ...

};