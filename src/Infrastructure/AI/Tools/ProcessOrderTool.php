<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use Neuron\Tools\Tool;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use App\Neuron\Nodes\ValidateInventoryNode;
use App\Neuron\Nodes\ProcessPaymentNode;
use App\Neuron\Nodes\CompleteOrderNode;

class ProcessOrderTool extends Tool
{
    // AIにこのツールの目的を教える
    protected string $name = 'process_order_workflow';
    protected string $description = 'ユーザーの契約確定処理（在庫引き当て、クレジットカード決済、注文完了の厳格なワークフロー）を実行します。ユーザーが「購入を確定する」と明確に同意した場合にのみ実行してください。';

    protected array $parameters = [
        'type' => 'object',
        'properties' => [
            'order_id' => [
                'type' => 'string',
                'description' => '処理対象となる注文ID（例: ORD-20260310-999）'
            ]
        ],
        'required' => ['order_id']
    ];

    public function execute(array $args): string
    {
        $orderId = $args['order_id'] ?? '';

        if (empty($orderId)) {
            return "エラー: 注文IDが指定されていません。";
        }

        try {
            // ワークフロー全体で共有する状態をセット
            $state = new WorkflowState();
            $state->set('order_id', $orderId);

            // ワークフローの組み立て
            $handler = Workflow::make()
                ->addNodes([
                    new ValidateInventoryNode(),
                    new ProcessPaymentNode(),
                    new CompleteOrderNode(),
                ])
                ->init($state);

            // 実行中の echo 出力をキャプチャしてAIに返す（※本番環境ではLoggerを使用します）
            ob_start();
            $handler->run();
            $output = ob_get_clean();

            // AIにワークフローが成功したことと、そのログを伝える
            return "【システム通知】ワークフローが正常に完了しました。以下の処理が行われました：\n" . $output;

        } catch (\Exception $e) {
            return "【システム通知】ワークフローの実行中にエラーが発生し、処理が中断されました: " . $e->getMessage();
        }
    }
}