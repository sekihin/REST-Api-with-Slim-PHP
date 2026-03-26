<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use App\Domain\Inventory\InventoryService;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolCallableNotSet;

/**
 * 製品ライフサイクル確認ツール (CheckProductLifecycleTool)
 * AIエージェントが、特定の製品のサポート終了日と販売終了日を問い合わせるために使用するクラスです。
 * 製品名やSKU（最小管理単位）に基づいた検索機能を提供します。
 */
class CheckInventoryTool extends Tool 
{
    /**
     * @var InventoryService 在庫管理ドメインサービス
     */
    private readonly InventoryService $inventoryService;

    /**
     * ツールの識別名
     * LLMがツールを選択する際に使用する一意のキーです。
     */
    protected string $name = 'check_inventory';

    /**
     * ツールの説明
     * LLMはこの文章を読んで、どのような時にこのツールを使うべきか判断します。
     */
    protected ?string $description = '特定製品のサポート終了日と販売終了日を検索します。製品名またはSKUでの検索をサポートします。';

    /**
     * プロパティ定義
     */
    protected array $properties = [];

    /**
     * コンストラクタ
     * 依存性注入 (DI) により、実際の在庫データにアクセスするサービスを受け取ります。
     * @param InventoryService $inventoryService
     */
    public function __construct(InventoryService $inventoryService)
    {
        parent::__construct(
            name: $this->name,
            description: $this->description,
            properties: $this->buildProperties(),
            annotations: []
        );

        $this->inventoryService = $inventoryService;
        
        // 実行コールバックを設定
        $this->setCallable(fn (string $product_name) => $this->run($product_name));
    }

    /**
     * プロパティを構築
     */
    private function buildProperties(): array
    {
        return [
            new ToolProperty(
                name: 'product_name',
                type: PropertyType::STRING,
                description: '製品名のキーワード。例: "ワイヤレスマウス" や "iPhone 15"',
                required: true
            )
        ];
    }

    /**
     * ツールの実行ロジック
     * @param string $productName 製品名
     * @return string AIに返すJSON形式の実行結果
     */
    private function run(string $productName): string
    {
        try {
            // バリデーション
            if (empty($productName)) {
                return json_encode(['error' => '製品名は必須です'], JSON_UNESCAPED_UNICODE);
            }

            // ドメインサービスを呼び出して製品情報を検索
            // 名前での検索は部分一致などで複数の製品がヒットする可能性があるため、リスト形式で取得します。
            $items = $this->inventoryService->checkStockByName($productName);

            // 該当する製品が見つからなかった場合
            if (empty($items)) {
                return json_encode([
                    'status' => 'not_found',
                    'message' => "{$productName}' を含む製品が見つかりませんでした。"
                ], JSON_UNESCAPED_UNICODE);
            }

            // 検索結果をAIが読みやすい形式に整形（フォーマット）
            // 全てのDBカラムを返すとトークンを無駄に消費するため、
            // AIの回答生成に必要な重要フィールド（名前、SKU、サポート終了日、販売終了日）のみに絞り込みます。
            $result = array_map(function ($item) {
                return [
                    'name' => $item['name'],
                    'sku' => $item['sku'],
                    'support_end_date' => $item['support_end_date'] ?? '未設定', // サポート終了日
                    'sales_end_date' => $item['sales_end_date'] ?? '未設定',   // 販売終了日
                    // 必要に応じて追加情報を追加可能
                ];
            }, $items);

            // 成功レスポンスの構築
            return json_encode([
                'status' => 'success',
                'count' => count($result), // ヒット件数
                'items' => $result         // 整形済みの製品リスト
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            // エラーハンドリング
            // 本番環境（Production）ではここで詳細なログを記録すべきです。
            // error_log($e->getMessage());

            // AIには内部エラーの詳細を見せず、シンプルなメッセージを返します。
            return json_encode(['error' => '製品情報システムは一時的に混み合っています'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * オプション：親クラスの execute をオーバーライド（カスタム実行ロジックが必要な場合）
     */
    public function execute(): void
    {
        try {
            parent::execute();
        } catch (MissingCallbackParameter | ToolCallableNotSet $e) {
            // 親クラスから投げられたパラメータ/コールバック例外をキャッチし、ビジネスメッセージに変換
            $this->setResult(json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}