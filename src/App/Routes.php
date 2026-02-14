<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use App\Application\Controllers\ChatController;
use App\Application\Controllers\OrderController;     // 新規追加: 注文管理コントローラー
use App\Application\Controllers\InventoryController; // 新規追加: 在庫管理コントローラー

/**
 * アプリケーションルート定義
 * * Slimアプリインスタンスを受け取り、各ルートを登録するクロージャを返します。
 * * グローバルミドルウェア（CORS, BodyParsingなど）は別途 App.php 等で適用されている前提です。
 */
return function (App $app) {

    // 環境変数からパスを取得 (設定がない場合はルート直下とする修正を推奨)
    $aliasPath = getenv('SLIM_ALIAS_PATH');
    if (!$aliasPath) {
        // '/api-test/' だと URLが /agent/getAccessToken になってしまうため、
        // シンプルに '/' に変更するか、空文字にするのが一般的です。
        // ここでは既存のロジックを尊重しつつ、空文字(ルート)にしています。
        $aliasPath = '/'; 
    }

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
    $app->map(['GET', 'POST'], $aliasPath . 'getAccessToken', 'App\Controller\uisAgent:getAccessToken');

};