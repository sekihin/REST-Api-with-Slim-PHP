<?php

declare(strict_types=1);

namespace App\Workflows\CheckDelivery\Nodes;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\WorkflowState;
use App\Workflows\CheckDelivery\Events\InputValidatedEvent;
use App\Workflows\CheckDelivery\Events\OrderFetchedEvent;
use App\Domain\Order\OrderService;

class FetchOrderNode extends Node
{
    public function __construct(private OrderService $orderService) {}

    public function __invoke(InputValidatedEvent $event, WorkflowState $state): OrderFetchedEvent|StopEvent
    {
        try {
            $order = $this->orderService->getOrderById($event->orderId);

            if (!$order) {
                $state->set('result_json', json_encode([
                    'status' => 'error',
                    'message' => '該当する契約が見つかりませんでした。'
                ], JSON_UNESCAPED_UNICODE));
                
                return new StopEvent(); // データなしの場合は終了
            }

            // 成功時は取得した Order エンティティを次のノードへ渡す
            return new OrderFetchedEvent($order);

        } catch (\Throwable $e) {
            $state->set('result_json', json_encode([
                'status' => 'error',
                'message' => '配送状況の照会中にエラーが発生しました。'
            ], JSON_UNESCAPED_UNICODE));
            
            return new StopEvent();
        }
    }
}