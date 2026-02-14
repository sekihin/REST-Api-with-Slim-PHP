<?php

declare(strict_types=1);

namespace App\Domain\Order;

use DateTimeImmutable;

/**
 * 注文エンティティ
 * * 1つの注文データを表現するドメインオブジェクトです。
 * * 注文ID、ステータス、合計金額、注文日時、および含まれる商品のリストを保持します。
 */
class Order
{
    /**
     * コンストラクタ
     * 
     * @param string $id 注文ID
     * @param string $status 注文ステータス (例: 'pending'=保留, 'shipped'=出荷済, 'delivered'=配達完了)
     * @param float $total 合計金額
     * @param DateTimeImmutable $createdAt 注文日時
     * @param array<int, array{name: string, quantity: int, price: float}> $items 商品リスト
     * 各要素は ['name' => 商品名, 'quantity' => 数量, 'price' => 単価] の配列構造を持ちます。
     */
    public function __construct(
        private string $id,
        private string $status,
        private float $total,
        private DateTimeImmutable $createdAt,
        private array $items
    ) {
    }

    /**
     * 注文IDを取得
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * 注文ステータスを取得
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * 合計金額を取得
     */
    public function getTotal(): float
    {
        return $this->total;
    }

    /**
     * 注文日時を取得
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * 商品リストの要約を取得
     *
     * @return array<string> 整形された商品情報のリスト
     */
    public function getItemsSummary(): array
    {
        return array_map(function ($item) {
            return sprintf(
                "%s x%d (単価: %.2f)",
                $item['name'],
                $item['quantity'],
                $item['price']
            );
        }, $this->items);
    }
}
