<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use App\Workflows\CheckDelivery\CheckDeliveryWorkflow;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolCallableNotSet;

/**
 * 配送状況照会ツール (CheckDeliveryTool)
 * 内部で CheckDeliveryWorkflow を実行し、結果を返します。
 */
class CheckDeliveryTool extends Tool
{
    private CheckDeliveryWorkflow $workflow;

    protected string $name = 'check_delivery_workflow';
    protected ?string $description = '契約番号に基づいて配送状況を照会します。契約の存在を確認した上で呼び出してください。';
    protected array $properties = [];

    public function __construct(CheckDeliveryWorkflow $workflow)
    {
        parent::__construct(
            name: $this->name,
            description: $this->description,
            properties: $this->buildProperties(),
            annotations: []
        );

        $this->workflow = $workflow;
        $this->setCallable(fn (string $order_id) => $this->run($order_id));
    }

    private function buildProperties(): array
    {
        return [
            new ToolProperty(
                name: 'order_id',
                type: PropertyType::STRING,
                description: '配送状況を照会する注文ID（例: ORD-2023-001）',
                required: true
            )
        ];
    }

    private function run(string $orderId): string
    {
        // ワークフローに初期状態（オーダーID）を渡して実行
        $finalState = $this->workflow->init([
            'order_id' => $orderId
        ])->run();

        // ワークフローの最終ノードが生成した JSON を返す
        return $finalState->get('result_json') ?? json_encode(['status' => 'error', 'message' => '不明なエラー']);
    }

    public function execute(): void
    {
        try {
            parent::execute();
        } catch (MissingCallbackParameter | ToolCallableNotSet $e) {
            $this->setResult(json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}