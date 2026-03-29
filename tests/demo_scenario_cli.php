<?php

/***
 * 下記実際のシナリオに関するデモです。

# 実際の使用シナリオ（契約システム）

ユーザーからの問い合わせ：

> 「昨日購入した商品がなぜ発送されていないのですか？」

Neuron AI は以下の処理を実行できます：

1. 自然言語を解析
2. 契約照会ツールを呼び出し
3. 在庫システムを確認
4. 異常状態かどうかを判断
5. カスタマーサポート用の返信を自動生成
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Domain\Order\Order;
use App\Domain\Order\OrderRepository;
use App\Domain\Inventory\InventoryService;
use App\Infrastructure\AI\Agents\RouterAgent;
use App\Infrastructure\AI\Tools\LookupOrderTool;
use App\Infrastructure\AI\Tools\RefundOrderTool; // 使用しなくても読み込みが必要
use App\Infrastructure\AI\Tools\CheckInventoryTool;
use App\Infrastructure\External\DeepSeekProvider; // または GeminiProvider
use Psr\Log\NullLogger;

// --- 1. 模擬環境構築 (Mocking) ---

// A. ユーザー情報の模擬
$userId = 'USER_888';
$userName = '田中 太郎';

// B. 「昨日」作成された契約の模擬 (ステータス: 処理中)
$date = new DateTimeImmutable();
$yesterday = $date->modify('-1 day'); // 新しいインスタンスが返される
$orderId = 'ORD-' . $yesterday->format('Ymd') . '-001';
$productName = 'Sony PlayStation 5 Pro';

$mockOrder = new Order(
    id: $orderId,
    status: '処理中', // 処理中、発送前の状態
    total: 119980,
    items: [
        ['name' => $productName, 'quantity' => 1, 'price' => 119980]
    ],
    createdAt: $yesterday // Orderエンティティにこのフィールドが存在するものとする
);

// C. 在庫情報の模擬 (在庫数0で発送不能となる)
$mockInventory = [
    $productName => 0 // 在庫切れ！
];

echo "========================================\n";
echo "🛠️  シナリオ設定\n";
echo "ユーザー: {$userName} (ID: {$userId})\n";
echo "契約番号: {$orderId} (契約日: 昨日、商品: {$productName})\n";
echo "在庫状況: {$productName} = 0 (在庫切れ)\n";
echo "========================================\n\n";

// --- 2. 依存性注入 (手動で組み立て、コンテナの模擬) ---

// OrderRepositoryの模擬
$orderRepo = new class($mockOrder, $userId) implements OrderRepository {
    private $order;
    private $uid;
    public function __construct($order, $uid) { $this->order = $order; $this->uid = $uid; }
    
    public function findOrderOfId(string $id): ?Order {
        // 簡易模擬：IDが一致するか、最近の契約を検索する場合はこの契約を返す
        return $this->order;
    }
    
    // 新しいメソッドの模擬：ユーザーの最新契約を検索
    public function findLatestOrder(string $userId): ?Order {
        return ($userId === $this->uid) ? $this->order : null;
    }
};

// OrderServiceの模擬
// OrderService に getLatestOrder メソッドが追加されたため、直接使用可能
$orderService = new \App\Domain\Order\OrderService($orderRepo);

// InventoryServiceの模擬
$inventoryService = new class($mockInventory) extends InventoryService {
    private $stock;
    public function __construct($stock) { $this->stock = $stock; }
    public function checkStockByName(string $name): array {
        // あいまい検索の模擬
        foreach ($this->stock as $prod => $qty) {
            if (str_contains($prod, $name)) {
                return [['name' => $prod, 'sku' => 'TEST-SKU', 'quantity' => $qty]];
            }
        }
        return [];
    }
};

// --- 3. AI Agentのインスタンス化 ---

// ツールの初期化
$lookupTool = new LookupOrderTool($orderService);
$refundTool = new RefundOrderTool($orderService, new NullLogger());
$inventoryTool = new CheckInventoryTool($inventoryService);

// LLMの初期化 (思考プロセスをデモするためMock LLMを使用、実際のDeepSeekProviderに置き換えも可能)
// デモ効果のため、LLMがすでに「思考ステップ」を導き出したものと仮定

/* 実環境での使用例: */
// $llm = new DeepSeekProvider(getenv('DEEPSEEK_API_KEY'), new NullLogger());

/* デモ環境 (Mock LLM出力): */
// AIの思考プロセスを模擬するため、Agentの実行ステップを手動で出力
echo "🤖 Agentが起動しました。ユーザーのリクエストを処理中...\n";
echo "ユーザークエリ: 「昨日購入した商品がなぜ発送されていないのですか？」\n\n";

// --- 4. Agentの思考プロセス模擬 (Chain of Thought) ---

// STEP 1: 意図の分析
echo "🧠 [思考]: ユーザーが発送状況を問い合わせています。契約番号は提供されていませんが、「昨日」という情報があるため、まず該当ユーザーの最新契約を検索するツールを呼び出す必要があります。\n";

// Action 1: 契約の検索
echo "🔧 [ツール呼び出し]: lookup_order(user_id='{$userId}', time_range='latest')\n";
$orderData = $orderService->getLatestOrder($userId); // ツール呼び出しの模擬
echo "   -> [ツール出力]: 契約 {$orderData->getId()} を発見しました。ステータス: '処理中'。商品: '{$productName}'。\n";

// STEP 2: ステータスの分析
echo "\n🧠 [思考]: 契約を特定しました。ステータスは「処理中」です。これが発送されていない理由を説明しています。ただし、根本原因を確認する必要があります。通常は在庫切れが原因です。該当商品の在庫を確認します。\n";

// Action 2: 在庫の確認
echo "🔧 [ツール呼び出し]: check_inventory(product_name='{$productName}')\n";
$stockData = $inventoryService->checkStockByName($productName); // ツール呼び出しの模擬
echo "   -> [ツール出力]: {$productName}: 在庫数 = 0。\n";

// STEP 3: 最終的な判断
echo "\n🧠 [思考]: 原因が判明しました。在庫数が0のため、契約が処理中の状態にスタックしています。ユーザーに在庫切れの状況を説明し、謝罪する必要があります。\n";

// --- 5. 最終的な返信の生成 ---

$finalResponse = <<<REPLY
{$userName} 様

確認したところ、昨日ご契約いただいた契約（契約番号：{$orderId}）は現在「処理中」のステータスとなっております。

調査の結果、ご購入いただいた商品 **「{$productName}」** は現在 **在庫切れ（在庫不足）** のため、発送が遅延しております。緊急的に補充を進めております。

ご不便をおかけし、誠に申し訳ございません。もしお待ちいただける場合は、発送完了後に速やかにご連絡いたします。また、契約のキャンセルまたは返金を希望される場合は、こちらにご連絡ください。
REPLY;

echo "\n💬 [Agentからの返信]:\n";
echo "--------------------------------------------------\n";
echo $finalResponse . "\n";
echo "--------------------------------------------------\n";