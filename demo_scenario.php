<?php

require __DIR__ . '/vendor/autoload.php';

use App\Domain\Order\Order;
use App\Domain\Order\OrderRepository;
use App\Domain\Inventory\InventoryService;
use App\Infrastructure\AI\Agents\OrderSupportAgent;
use App\Infrastructure\AI\Tools\LookupOrderTool;
use App\Infrastructure\AI\Tools\RefundOrderTool; // 即使不用也要加载
use App\Infrastructure\AI\Tools\CheckInventoryTool;
use App\Infrastructure\External\DeepSeekProvider; // 或 GeminiProvider
use Psr\Log\NullLogger;

// --- 1. 模拟环境搭建 (Mocking) ---

// A. 模拟用户
$userId = 'USER_888';
$userName = '田中 太郎';

// B. 模拟“昨天”下的订单 (状态: 处理中)
$date = new DateTimeImmutable();
$yesterday = $date->modify('-1 day'); // 新しいインスタンスが返る
$orderId = 'ORD-' . $yesterday->format('Ymd') . '-001';
$productName = 'Sony PlayStation 5 Pro';

$mockOrder = new Order(
    id: $orderId,
    status: 'Processing', // 处理中，尚未发货
    total: 119980,
    items: [
        ['name' => $productName, 'quantity' => 1, 'price' => 119980]
    ],
    createdAt: $yesterday // 假设 Order 实体有这个字段
);

// C. 模拟库存 (库存为 0，导致无法发货)
$mockInventory = [
    $productName => 0 // 缺货！
];

echo "========================================\n";
echo "🛠️  SCENARIO SETUP\n";
echo "User: {$userName} (ID: {$userId})\n";
echo "Order: {$orderId} (Placed: Yesterday, Item: {$productName})\n";
echo "Inventory: {$productName} = 0 (Out of Stock)\n";
echo "========================================\n\n";

// --- 2. 依赖注入 (手动组装，模拟 Container) ---

// 模拟 OrderRepository
$orderRepo = new class($mockOrder, $userId) implements OrderRepository {
    private $order;
    private $uid;
    public function __construct($order, $uid) { $this->order = $order; $this->uid = $uid; }
    
    public function findOrderOfId(string $id): ?Order {
        // 简单模拟：只要ID对或者是查询最近订单，都返回这个订单
        return $this->order;
    }
    
    // 模拟一个新方法：查找用户最近订单
    public function findLatestOrder(string $userId): ?Order {
        return ($userId === $this->uid) ? $this->order : null;
    }
};

// 模拟 OrderService
// 我们扩展一下 Service，允许它“查找用户最近的订单”
$orderService = new class($orderRepo) extends \App\Domain\Order\OrderService {
    public function getLatestOrder(string $userId): ?Order {
        return $this->orderRepository->findLatestOrder($userId);
    }
};

// 模拟 InventoryService
$inventoryService = new class($mockInventory) extends InventoryService {
    private $stock;
    public function __construct($stock) { $this->stock = $stock; }
    public function checkStockByName(string $name): array {
        // 模拟模糊搜索
        foreach ($this->stock as $prod => $qty) {
            if (str_contains($prod, $name)) {
                return [['name' => $prod, 'sku' => 'TEST-SKU', 'quantity' => $qty]];
            }
        }
        return [];
    }
};

// --- 3. 实例化 AI Agent ---

// 初始化工具
$lookupTool = new LookupOrderTool($orderService);
$refundTool = new RefundOrderTool($orderService, new NullLogger());
$inventoryTool = new CheckInventoryTool($inventoryService);

// 初始化 LLM (这里使用 Mock LLM 来演示思维链，或者你可以换成真实的 DeepSeekProvider)
// 为了演示效果，我们假设 LLM 已经“思考”出了步骤。
// 如果你有 API Key，替换为 new DeepSeekProvider(...) 即可看到真实推理。

/* 真实环境: */
// $llm = new DeepSeekProvider(getenv('DEEPSEEK_API_KEY'), new NullLogger());

/* 演示环境 (Mock LLM Output): */
// 我们手动打印 Agent 的执行步骤来模拟 AI 的思考过程
echo "🤖 Agent Activated. Processing user request...\n";
echo "User Query: \"我昨天买的那个商品怎么还没发货？\"\n\n";

// --- 4. 模拟 Agent 的思维链 (Chain of Thought) ---

// STEP 1: 分析意图
echo "🧠 [Thought]: 用户在询问发货状态。由于用户没提供订单号，但提到了“昨天”，我需要先调用工具查找该用户最近的订单。\n";

// Action 1: 查找订单
echo "🔧 [Call Tool]: lookup_order(user_id='{$userId}', time_range='latest')\n";
$orderData = $orderService->getLatestOrder($userId); // 模拟工具调用
echo "   -> [Tool Output]: Found Order {$orderData->getId()}. Status: 'Processing'. Item: '{$productName}'.\n";

// STEP 2: 分析状态
echo "\n🧠 [Thought]: 订单找到了，状态是 'Processing' (处理中)。这解释了为什么没发货。但我需要知道原因。通常是因为缺货。我需要检查该商品的库存。\n";

// Action 2: 查库存
echo "🔧 [Call Tool]: check_inventory(product_name='{$productName}')\n";
$stockData = $inventoryService->checkStockByName($productName); // 模拟工具调用
echo "   -> [Tool Output]: {$productName}: Quantity = 0.\n";

// STEP 3: 最终判断
echo "\n🧠 [Thought]: 真相大白。库存为 0，导致订单卡在处理中。我需要向用户解释缺货情况，并安抚用户。\n";

// --- 5. 生成最终回复 ---

$finalResponse = <<<REPLY
您好，{$userName}。

帮您查询到了，您昨天下的订单（订单号：{$orderId}）目前状态为“处理中”。

经过核实，您购买的商品 **"{$productName}"** 目前暂时 **缺货 (库存不足)**，这导致了发货延迟。我们正在紧急补货中。

非常抱歉给您带来了不便。如果您愿意等待，发货后我们会第一时间通知您；如果您需要取消订单或申请退款，也可以直接告诉我。
REPLY;

echo "\n💬 [Agent Response]:\n";
echo "--------------------------------------------------\n";
echo $finalResponse . "\n";
echo "--------------------------------------------------\n";