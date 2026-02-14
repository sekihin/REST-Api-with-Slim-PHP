<?php

declare(strict_types=1);

namespace App\Domain\Order;

/**
 * 注文リポジトリインターフェース
 * * 注文データの永続化（保存・取得）に関するメソッドを定義したインターフェースです。
 * * ドメイン層は、このインターフェースを通じてデータにアクセスし、具体的な実装（DB接続など）からは切り離されます。
 */
interface OrderRepository
{
    /**
     * 注文IDによる注文の検索
     * * 指定されたIDに対応する注文エンティティを取得します。
     * * @param string $id 検索対象の注文ID
     * @return Order|null 注文が見つかった場合はOrderオブジェクト、見つからない場合はnullを返します。
     */
    public function findOrderOfId(string $id): ?Order;
}