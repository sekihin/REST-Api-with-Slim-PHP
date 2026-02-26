<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use App\Domain\Order\OrderService;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolCallableNotSet;
use JsonException;
use InvalidArgumentException;

class LookupOrderTool extends Tool
{
    /**
     * 注文関連のビジネスロジックを扱うサービス
     */
    private readonly OrderService $orderService;

    /**
     * AIから渡される実行引数（复用父类 $inputs，无需自定义）
     */
    // 移除自定义 $args，直接使用父类的 $inputs 属性

    /**
     * ツールの識別名（严格匹配父类：protected string）
     */
    protected string $name = 'lookup_order';

    /**
     * ツールの説明（匹配父类：protected ?string）
     */
    protected ?string $description = '注文IDに基づいて注文詳細と現在のステータスを検索します。ユーザーが注文IDを提供していない場合は、先に尋ねてください。';

    /**
     * 父类的属性是 $properties（而非 $parameters），需按父类规范定义
     * 这里复用父类 $properties，通过构造函数初始化
     */
    protected array $properties = [];

    /**
     * 构造函数：必须调用父类构造函数，并传递必填参数 $name
     */
    public function __construct(OrderService $orderService)
    {
        // 调用父类构造函数（父类要求 $name 为必填 string）
        parent::__construct(
            name: $this->name,
            description: $this->description,
            properties: $this->buildProperties(), // 初始化参数校验规则
            annotations: []
        );

        $this->orderService = $orderService;
        
        // 绑定执行回调（父类要求通过 setCallable 绑定，或实现 __invoke）
        $this->setCallable(fn (string $order_id) => $this->run($order_id));
    }

    /**
     * 构建父类要求的 ToolProperty 规则（替代原自定义 $parameters）
     * 适配父类的参数校验逻辑
     */
    private function buildProperties(): array
    {
        // ToolProperty クラスを使用してプロパティを定義
        return [
            new ToolProperty(
                name: 'order_id',
                type: PropertyType::STRING,
                description: '注文IDの形式例: ORD-2023-001',
                required: true
            )
        ];
    }

    /**
     * 核心执行逻辑（替换原 run 方法，适配父类参数传递）
     */
    private function run(string $orderId): string
    {
        try {
            // 参数格式校验
            $this->validateOrderId($orderId);
            
            $order = $this->orderService->getOrderById($orderId);
            
            if (!$order) {
                return json_encode(
                    ['status' => 'error', 'message' => '注文が見つかりませんでした'],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
            }

            return json_encode([
                'id' => $order->getId(),
                'status' => $order->getStatus(),
                'total' => $order->getTotal(),
                'items' => $order->getItemsSummary()
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        } catch (InvalidArgumentException $e) {
            return json_encode(
                ['status' => 'error', 'message' => $e->getMessage()],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\Exception $e) {
            return json_encode(
                ['status' => 'error', 'message' => '検索サービスは一時的に利用できません'],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }
    }

    /**
     * 注文ID格式校验
     */
    private function validateOrderId(string $orderId): void
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            throw new InvalidArgumentException('「order_id」は空文字列にすることができません');
        }

        if (!preg_match('/^ORD-\d{4}-\d{3}$/', $orderId)) {
            throw new InvalidArgumentException('「order_id」の形式が不正です。例：ORD-2023-001');
        }
    }

    /**
     * 可选：重写父类 execute（若需自定义执行逻辑）
     * 若无需自定义，可直接使用父类 execute 方法
     */
    public function execute(): void
    {
        try {
            parent::execute();
        } catch (MissingCallbackParameter | ToolCallableNotSet $e) {
            // 捕获父类抛出的参数/回调异常，转为业务提示
            $this->setResult(json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}