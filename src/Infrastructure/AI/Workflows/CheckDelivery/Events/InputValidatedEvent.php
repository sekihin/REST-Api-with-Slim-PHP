<?php

declare(strict_types=1);

namespace App\Workflows\CheckDelivery\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * 1. 入力値のバリデーション完了イベント
 */
class InputValidatedEvent implements Event
{
    public function __construct(public string $orderId) {}
}

/**
 * 2. 契約情報の取得完了イベント
 * ※ $order の型は実際の App\Domain\Order\Order 等に合わせてください
 */
class OrderFetchedEvent implements Event
{
    public function __construct(public $order) {}
}