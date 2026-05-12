<?php

declare(strict_types=1);

namespace App\Workflows\CheckDelivery\Nodes;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\WorkflowState;
use App\Workflows\CheckDelivery\Events\InputValidatedEvent;

class ValidateInputNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): InputValidatedEvent|StopEvent
    {
        $orderId = trim((string) $state->get('order_id'));
        
        if ($orderId === '') {
            $state->set('result_json', json_encode([
                'status' => 'error',
                'message' => '契約番号は必須です。'
            ], JSON_UNESCAPED_UNICODE));
            
            return new StopEvent(); // バリデーション失敗時はワークフローを終了
        }

        // 成功時は次のノードへオーダーIDを引き継ぐ
        return new InputValidatedEvent($orderId);
    }
}