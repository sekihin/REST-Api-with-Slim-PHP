<?php

declare(strict_types=1);

namespace App\Domain\Order;

/**
 * 注文サービス
 * * 注文に関するビジネスロジック（取得、処理、検証など）をカプセル化するクラスです。
 * * コントローラーやAIツールは、リポジトリを直接叩くのではなく、このサービスを経由してデータにアクセスします。
 */
class OrderService
{
    /**
     * @var OrderRepository 注文リポジトリのインターフェース
     */
    private OrderRepository $orderRepository;

    /**
     * コンストラクタ
     * * 具象クラス（InMemoryOrderRepositoryなど）ではなく、インターフェース（OrderRepository）を
     * * 依存性注入（DI）することで、DIP（依存性逆転の原則）に従っています。
     * * これにより、将来的にDB実装が変わってもこのクラスを修正する必要がなく、テストも容易になります。
     * * @param OrderRepository $orderRepository
     */
    public function __construct(OrderRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    /**
     * IDによる注文の取得
     * * 注文IDを受け取り、対応する注文エンティティを返します。
     * * @param string $orderId 注文ID
     * @return Order|null 注文が見つかった場合はOrderオブジェクト、なければnull
     */
    public function getOrderById(string $orderId): ?Order
    {
        // ビジネスロジックの適用ポイント：
        // 単にデータを取得するだけでなく、以下のような処理をここに挟むことが一般的です。
        // - 現在のユーザーがこの注文を閲覧する権限（Permission）を持っているかチェック
        // - 監査ログ（Audit Log）へのアクセス記録
        // - 入力値の正規化（フォーマット整形）

        // IDの前後の空白を除去（サニタイズ処理）
        $orderId = trim($orderId);
        
        // リポジトリを利用してデータを検索
        return $this->orderRepository->findOrderOfId($orderId);
    }
}