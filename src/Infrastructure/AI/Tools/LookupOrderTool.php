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

/**
 * 契約照会ツール (LookupOrderTool)
 * AIエージェントが契約IDに基づいて契約の詳細情報とステータスを検索するためのクラス
 */
class LookupOrderTool extends Tool
{
    /**
     * 契約関連のビジネスロジックを扱うドメインサービス
     */
    private readonly OrderService $orderService;

    /**
     * ツールの一意識別名
     */
    protected string $name = 'lookup_order';

    /**
     * ツールの説明文
     */
    protected ?string $description = '契約IDに基づいて契約詳細（注文種別を含む）と現在のステータスを検索します。ユーザーが契約IDを提供していない場合は、先に尋ねてください。';

    /**
     * ツールの実行パラメータ定義
     */
    protected array $properties = [];

    /**
     * コンストラクタ
     * @param OrderService $orderService 契約ドメインサービス
     */
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

    /**
     * ツールの実行パラメータルールを構築
     * @return ToolProperty[] パラメータ定義の配列
     */
    private function buildProperties(): array
    {
        return [
            new ToolProperty(
                name: 'order_id',
                type: PropertyType::STRING,
                description: '契約IDの形式例: ORD-2023-001',
                required: true
            )
        ];
    }

    /**
     * ツールの核心実行ロジック
     * 契約IDを受け取り、契約情報を検索してJSON形式で返却する
     * @param string $orderId 照会対象の契約ID
     * @return string JSON形式の実行結果
     */
    private function run(string $orderId): string
    {
        try {
            $this->validateOrderId($orderId);
            
            $order = $this->orderService->getOrderById($orderId);
            
            if (!$order) {
                return json_encode(
                    ['status' => 'error', 'message' => '契約が見つかりませんでした'],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
            }

            // 【追加】ドメインモデルから注文種別（買い切り、サブスク等）を取得
            // ※ メソッド名 (getOrderType) は実際の Order クラスの仕様に合わせてください。
            $orderType = method_exists($order, 'getOrderType') ? $order->getOrderType() : 'unknown';

            // 正常系のレスポンス（AIが利用しやすい形式に整形）
            return json_encode([
                'id' => $order->getId(),
                'status' => $order->getStatus(),
                'order_type' => $orderType, // AIが「買い切り」か判定するためのキーを追加
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
     * 契約IDの形式バリデーション
     * @param string $orderId 検証対象の契約ID
     * @throws InvalidArgumentException バリデーションエラー時にスロー
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
     * 親クラスのexecuteメソッドをオーバーライド
     */
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