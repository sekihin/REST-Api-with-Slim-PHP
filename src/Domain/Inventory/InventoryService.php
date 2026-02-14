<?php

namespace App\Domain\Inventory;

/**
 * 在庫管理サービス
 * * 商品の在庫状況を確認するためのビジネスロジックを提供するクラスです。
 * * 注: 本番環境では InventoryRepository を注入してデータベースにアクセスしますが、
 * * ここではデモ用に簡易的なモックデータを使用しています。
 */
class InventoryService
{
    // 本来はここでリポジトリインターフェースを依存性注入 (DI) します
    // private InventoryRepository $inventoryRepository;

    /**
     * 商品名による在庫検索
     * * 指定されたキーワードを含む商品を検索し、在庫情報を返します。
     * *
     * * @param string $name 検索する商品名（部分一致）
     * * @return array 見つかった商品のリスト（各要素は ['name', 'sku', 'quantity']）
     */
    public function checkStockByName(string $name): array
    {
        // データベース検索のロジックをシミュレート
        // 実際のSQLイメージ: SELECT * FROM products WHERE name LIKE :name
        
        // モックデータ（ダミーの商品リスト）
        $mockDb = [
            // Logicool G304 ワイヤレスマウス (在庫あり: 45)
            ['name' => 'Logitech G304 ワイヤレスマウス', 'sku' => 'LOGI-G304', 'quantity' => 45],
            // Logicool G502 有線マウス (在庫なし)
            ['name' => 'Logitech G502 有線マウス', 'sku' => 'LOGI-G502', 'quantity' => 0],
            // Razer Viper Ultimate (在庫少: 12)
            ['name' => 'Razer Viper Ultimate', 'sku' => 'RAZER-VIPER', 'quantity' => 12],
            // Apple iPhone 15 (在庫僅少: 5)
            ['name' => 'Apple iPhone 15', 'sku' => 'APL-IP15-128', 'quantity' => 5],
        ];

        $results = [];
        // 配列をループして検索処理を実行（SQLのLIKE検索の代わり）
        foreach ($mockDb as $item) {
            // PHP 8.0以降の str_contains 関数を使用
            // 商品名に検索キーワードが含まれているかチェック
            if (str_contains($item['name'], $name)) {
                $results[] = $item;
            }
        }
        
        return $results;
    }
}