<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Order\Order;
use App\Domain\Order\OrderRepository;

/**
 * インメモリ注文リポジトリ
 * * 実際のデータベースを使用せず、メモリ上の配列で注文データを管理するリポジトリ実装です。
 * * 主に単体テストや、AIエージェントの動作確認（プロトタイピング）用に使用されます。
 */
class InMemoryOrderRepository implements OrderRepository
{
    /**
     * @var Order[] 注文データのリスト（キーは注文ID）
     */
    private array $orders;

    /**
     * コンストラクタ
     * * AIエージェントのテスト用に、ハードコードされたダミーデータを初期化します。
     */
    public function __construct()
    {
        // エージェントの動作確認用ダミーデータ定義
        $this->orders = [
            // ケース1: 既に出荷済みの通常注文
            'ORD-2023-001' => new Order(
                'ORD-2023-001',
                '出荷済み', // ステータス: 出荷済み
                299.00,   // 合計金額
                [
                    // 商品: ワイヤレスマウス
                    ['name' => 'ワイヤレスマウス', 'quantity' => 1, 'price' => 59.00],
                    // 商品: メカニカルキーボード
                    ['name' => 'メカニカルキーボード', 'quantity' => 1, 'price' => 240.00],
                ]
            ),
            // ケース2: 処理中の高額注文
            'ORD-2023-002' => new Order(
                'ORD-2023-002',
                '準備中', // ステータス: 処理中
                8999.00,
                [
                    // 商品: ゲーミングノートPC
                    ['name' => 'ゲーミングノートPC', 'quantity' => 1, 'price' => 8999.00],
                ]
            ),
        ];
    }

    /**
     * IDによる注文検索
     * * データベースへの問い合わせをシミュレートし、メモリ上の配列から該当する注文を返します。
     * * @param string $id 注文ID
     * @return Order|null 注文が見つかった場合はOrderオブジェクト、見つからない場合はnull
     */
    public function findOrderOfId(string $id): ?Order
    {
        // 配列のキー存在確認を行い、データを取得（DB検索の代替処理）
        return $this->orders[$id] ?? null;
    }

    /**
     * ユーザーIDによる最新注文の検索
     * * 注：この実装は簡易版で、実際の実装ではユーザーIDと注文の関連を管理する必要があります。
     * * @param string $userId ユーザーID
     * @return Order|null 注文が見つかった場合はOrderオブジェクト、見つからない場合はnull
     */
    public function findLatestOrder(string $userId): ?Order
    {
        // 簡易実装：最初の注文を返す（実際の実装では、ユーザーIDに基づいて最新の注文を返す必要があります）
        // このリポジトリはユーザーIDを保持していないため、nullを返します
        // 実際の実装では、OrderエンティティにuserIdフィールドを追加するか、
        // 別のマッピングテーブルを使用する必要があります
        return null;
    }
}