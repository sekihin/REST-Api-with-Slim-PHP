<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use App\Domain\Order\OrderService;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolCallableNotSet;
use InvalidArgumentException;

/**
 * 配送状況照会ツール (CheckDeliveryTool)
 * AIエージェントがユーザーに代わって注文の配送状況を照会するためのツールです。
 */
class CheckDeliveryTool extends Tool
{
    private readonly OrderService $orderService;

    protected string $name = 'check_delivery';

    protected ?string $description = '注文IDに基づいて配送状況を照会します。契約の存在を確認した上で呼び出してください。';

    protected array $properties = [];

    public function __construct(OrderService $orderService)
    {
        parent::__construct(
            name: $this->name,
            description: $this->description,
            properties: $this->buildProperties(),
            annotations: []
        );

        $this->orderService = $orderService;
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
        $orderId = trim($orderId);
        if ($orderId === '') {
            return json_encode([
                'status' => 'error',
                'message' => '注文番号は必須です。'
            ], JSON_UNESCAPED_UNICODE);
        }

        try {
            $order = $this->orderService->getOrderById($orderId);

            if (!$order) {
                return json_encode([
                    'status' => 'error',
                    'message' => '該当する注文が見つかりませんでした。'
                ], JSON_UNESCAPED_UNICODE);
            }

            $statusLabel = $this->mapStatusToLabel($order->getStatus());

            return json_encode([
                'order_id' => $order->getId(),
                'delivery_status' => $order->getStatus(),
                'delivery_status_label' => $statusLabel,
                'ordered_at' => $order->getCreatedAt()->format('Y-m-d H:i'),
            ], JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode([
                'status' => 'error',
                'message' => '配送状況の照会は一時的に利用できません。'
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    private function mapStatusToLabel(string $status): string
    {
        return match (strtolower($status)) {
            'pending' => '受付済み・未出荷',
            'shipped' => '出荷済み',
            'delivered' => '配達完了',
            'cancelled' => 'キャンセル',
            default => $status,
        };
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
