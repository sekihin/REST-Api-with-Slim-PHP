<?php

// src/Infrastructure/AI/Tools/LookupOrderTool.php

namespace App\Infrastructure\AI\Tools;

use Neuron\Tools\Tool;
use App\Domain\Order\OrderService;

/**
 * 注文情報検索ツール
 * * AIエージェントが注文IDに基づいて注文の詳細情報を取得するために使用するツールクラスです。
 */
class LookupOrderTool extends Tool
{
    /**
     * @var OrderService 注文関連のビジネスロジックを扱うサービス
     */
    private OrderService $orderService;

    /**
     * コンストラクタ
     * * DI（依存性注入）を利用して、実際のビジネスロジックサービスを注入します。
     * * @param OrderService $orderService
     */
    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /** * ツールの識別名 
     */
    protected string $name = 'lookup_order';

    /** * ツールの説明
     * * LLMはこの説明を読んで、いつこのツールを使用すべきかを判断します。
     * 内容: "注文IDに基づいて注文詳細と現在のステータスを検索します。ユーザーが注文IDを提供していない場合は、先に尋ねてください。"
     */
    protected string $description = '注文IDに基づいて注文詳細と現在のステータスを検索します。ユーザーが注文IDを提供していない場合は、先に尋ねてください。';

    /**
     * パラメータスキーマ定義
     * * LLMがどのような引数を渡すべきかを理解するためのJSON Schema構造です。
     */
    protected array $parameters = [
        'type' => 'object',
        'properties' => [
            'order_id' => [
                'type' => 'string',
                'description' => '例如: ORD-2023-001' // 例: ORD-2023-001
            ]
        ],
        'required' => ['order_id']
    ];

    /**
     * ツールを実行する
     * * @param array $args AIから渡された引数（['order_id' => '...']）
     * @return string AIに返すJSON形式の実行結果
     */
    public function execute(array $args): string
    {
        try {
            // サービス層を利用して注文情報を取得
            $order = $this->orderService->getOrderById($args['order_id']);
            
            // 注文が見つからない場合のエラーハンドリング
            if (!$order) {
                // "注文が見つかりませんでした" というメッセージをJSONで返す
                return json_encode(['status' => 'error', 'message' => '注文が見つかりませんでした']);
            }

            // トークン節約のため、AIの判断に必要な重要なフィールドのみを抽出して返します。
            // 全データを返すとコンテキスト長を圧迫する可能性があるためです。
            return json_encode([
                'id' => $order->getId(),
                'status' => $order->getStatus(),
                'total' => $order->getTotal(),
                'items' => $order->getItemsSummary()
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            // システムログを記録しつつ（ここでは省略）、AIにはフレンドリーなエラー情報を返します。
            // 内部エラーの詳細をそのままAIに見せないための措置です。
            return json_encode(['error' => '検索サービスは一時的に利用できません']); // "検索サービスは一時的に利用できません"
        }
    }
}