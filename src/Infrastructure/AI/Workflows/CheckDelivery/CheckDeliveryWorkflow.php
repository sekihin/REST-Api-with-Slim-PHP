<?php

declare(strict_types=1);

namespace App\Workflows\CheckDelivery;

use NeuronAI\Workflow\Workflow;
use App\Workflows\CheckDelivery\Nodes\ValidateInputNode;
use App\Workflows\CheckDelivery\Nodes\FetchOrderNode;
use App\Workflows\CheckDelivery\Nodes\EvaluateDeliveryNode;

class CheckDeliveryWorkflow extends Workflow
{
    public function __construct(
        private Nodes\ValidateInputNode $validateNode,
        private Nodes\FetchOrderNode $fetchNode,
        private Nodes\EvaluateDeliveryNode $evaluateNode
    ) {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [
            $this->validateNode,
            $this->fetchNode,
            $this->evaluateNode,
        ];
    }
}