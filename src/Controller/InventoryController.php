<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Domain\Inventory\InventoryService;

/**
 * 在庫管理コントローラー
 * * 商品在庫に関する問い合わせを受け付けるAPIです。
 */
class InventoryController
{
    private InventoryService $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * 在庫検索API
     * * GET /api/inventory/search?name=xxx
     * *
     * * クエリパラメータ 'name' を受け取り、部分一致する商品の在庫状況を返します。
     * * AIエージェントの `CheckInventoryTool` と同様の検索機能を提供します。
     */
    public function search(Request $request, Response $response): Response
    {
        // クエリパラメータの取得 ($request->getQueryParams())
        $queryParams = $request->getQueryParams();
        $name = $queryParams['name'] ?? '';

        // 入力バリデーション
        if (empty($name)) {
            $response->getBody()->write(json_encode(['error' => 'Query parameter "name" is required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // ドメインサービスの呼び出し
        // データベース（またはモック）から在庫情報を取得
        $items = $this->inventoryService->checkStockByName($name);

        // 結果の返却
        // フロントエンド向けに、データ本体と件数を含めたJSONを構築
        $response->getBody()->write(json_encode([
            'data' => $items,
            'count' => count($items)
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }
}