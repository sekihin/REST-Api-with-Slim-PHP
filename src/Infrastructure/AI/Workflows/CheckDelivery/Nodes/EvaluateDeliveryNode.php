<?php

declare(strict_types=1);

namespace App\Workflows\CheckDelivery\Nodes;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\WorkflowState;
use App\Workflows\CheckDelivery\Events\OrderFetchedEvent;

class EvaluateDeliveryNode extends Node
{
    public function __invoke(OrderFetchedEvent $event, WorkflowState $state): StopEvent
    {
        $order = $event->order;
        $orderType = method_exists($order, 'getOrderType') ? $order->getOrderType() : 'unknown';

        if ($orderType !== 'buyout' && $orderType !== '買取') {
            $result = [
                'order_id' => $order->getId(),
                'delivery_status' => 'unshippable',
                'delivery_status_label' => '出荷不可（発送なし）',
                'message' => 'この契約は買い切り（買取）注文ではないため、物理的な出荷は発生しません。',
                'ordered_at' => $order->getCreatedAt()->format('Y-m-d H:i'),
            ];
        } else {
            $result = [
                'order_id' => $order->getId(),
                'delivery_status' => $order->getStatus(),
                'delivery_status_label' => $this->mapStatusToLabel($order->getStatus()),
                'ordered_at' => $order->getCreatedAt()->format('Y-m-d H:i'),
            ];
        }

        // 最終的なJSONをステートに保存
        $state->set('result_json', json_encode($result, JSON_UNESCAPED_UNICODE));
        
        // ワークフローの正常終了
        return new StopEvent();
    }

    private function mapStatusToLabel(string $status): string
    {
        return match (strtolower($status)) {
            'pending' => '受付済み・未出荷',
            'shipped' => '出荷済み',
            'delivered' => '配達完了',
            'cancelled' => 'キャンセル',
            'unshippable' => '出荷不可（発送なし）',
            default => $status,
        };
    }
}