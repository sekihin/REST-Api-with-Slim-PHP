<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use Neuron\Tools\Tool;
use App\Domain\Inventory\InventoryService;

/**
 * 在庫確認ツール (CheckInventoryTool)
 * * AIエージェントが、特定の商品のリアルタイム在庫数を問い合わせるために使用するクラスです。
 * * 商品名やSKU（最小管理単位）に基づいた検索機能を提供します。
 */
class CheckInventoryTool extends Tool
{
    /**
     * @var InventoryService 在庫管理ドメインサービス
     */
    private InventoryService $inventoryService;

    /**
     * コンストラクタ
     * * 依存性注入 (DI) により、実際の在庫データにアクセスするサービスを受け取ります。
     * * @param InventoryService $inventoryService
     */
    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /** * ツールの識別名 
     * LLMがツールを選択する際に使用する一意のキーです。
     */
    protected string $name = 'check_inventory';

    /** * ツールの説明
     * * LLMはこの文章を読んで、どのような時にこのツールを使うべきか判断します。
     * * 内容（訳）: "特定商品のリアルタイム在庫数を検索します。商品名またはSKUでの検索をサポートします。"
     */
    protected string $description = '特定商品のリアルタイム在庫数を検索します。商品名またはSKUでの検索をサポートします。';

    /**
     * パラメータのJSON Schema定義
     * * LLMがツールを呼び出す際に、どのような引数（商品名など）が必要かを定義します。
     */
    protected array $parameters = [
        'type' => 'object',
        'properties' => [
            'product_name' => [
                'type' => 'string',
                // 説明文（訳）: 商品名のキーワード。例: "ワイヤレスマウス" や "iPhone 15"
                'description' => '商品名のキーワード。例: "ワイヤレスマウス" や "iPhone 15"'
            ]
        ],
        'required' => ['product_name'] // 商品名は必須
    ];

    /**
     * ツールの実行ロジック
     * * @param array $args LLMから渡された引数 (['product_name' => '...'])
     * @return string AIに返すJSON形式の実行結果
     */
    public function execute(array $args): string
    {
        try {
            // 入力パラメータの取得とバリデーション
            $productName = $args['product_name'] ?? '';
            
            if (empty($productName)) {
                return json_encode(['error' => '商品名は必須です']); // エラー: 商品名は必須です
            }

            // ドメインサービスを呼び出して在庫を検索
            // 名前での検索は部分一致などで複数の商品がヒットする可能性があるため、リスト形式で取得します。
            $items = $this->inventoryService->checkStockByName($productName);

            // 該当する商品が見つからなかった場合
            if (empty($items)) {
                return json_encode([
                    'status' => 'not_found',
                    'message' => "{$productName}' を含む商品が見つかりませんでした。" // "〜を含む商品が見つかりませんでした"
                ], JSON_UNESCAPED_UNICODE);
            }

            // 検索結果をAIが読みやすい形式に整形（フォーマット）
            // 全てのDBカラムを返すとトークンを無駄に消費するため、
            // AIの回答生成に必要な重要フィールド（名前、SKU、在庫数、ステータス）のみに絞り込みます。
            $result = array_map(function ($item) {
                return [
                    'name' => $item['name'],
                    'sku' => $item['sku'],
                    'stock_qty' => $item['quantity'],
                    // 在庫数に基づいて、AIが判断しやすいテキストステータスを付与
                    'status' => $item['quantity'] > 0 ? 'In Stock' : 'Out of Stock',
                    
                    // 必要に応じて倉庫の場所などの情報を追加可能ですが、
                    // 現状はビジネス要件に応じてコメントアウトしています。
                    // 'warehouse' => $item['warehouse_location'] 
                ];
            }, $items);

            // 成功レスポンスの構築
            return json_encode([
                'status' => 'success',
                'count' => count($result), // ヒット件数
                'items' => $result         // 整形済みの商品リスト
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            // エラーハンドリング
            // 本番環境（Production）ではここで詳細なログを記録すべきです。
            // error_log($e->getMessage());

            // AIには内部エラーの詳細を見せず、シンプルなメッセージを返します。
            return json_encode(['error' => '在庫システムは一時的に混み合っています']); // "在庫システムは一時的に混み合っています"
        }
    }
}