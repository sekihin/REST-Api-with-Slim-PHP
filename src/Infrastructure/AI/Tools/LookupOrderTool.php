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
     * LLMがツールを選択する際に使用するキー（親クラスのプロパティを継承）
     */
    protected string $name = 'lookup_order';

    /**
     * ツールの説明文
     * LLMがツールの用途を理解するためのテキスト（親クラスのプロパティを継承）
     */
    protected ?string $description = '契約IDに基づいて契約詳細と現在のステータスを検索します。ユーザーが契約IDを提供していない場合は、先に尋ねてください。';

    /**
     * ツールの実行パラメータ定義
     * 親クラスのプロパティを継承し、コンストラクタで初期化
     */
    protected array $properties = [];

    /**
     * コンストラクタ
     * 依存性注入により契約サービスを受け取り、親クラスの初期化とコールバック設定を行う
     * @param OrderService $orderService 契約ドメインサービス
     */
    public function __construct(OrderService $orderService)
    {
        // 親クラスのコンストラクタを呼び出し（必須パラメータを渡す）
        parent::__construct(
            name: $this->name,
            description: $this->description,
            properties: $this->buildProperties(), // パラメータ検証ルールを初期化
            annotations: []
        );

        $this->orderService = $orderService;
        
        // ツール実行時のコールバック関数を設定（親クラスの仕様に従う）
        $this->setCallable(fn (string $order_id) => $this->run($order_id));
    }

    /**
     * ツールの実行パラメータルールを構築
     * 親クラスが要求するToolProperty形式でパラメータの定義を行う
     * @return ToolProperty[] パラメータ定義の配列
     */
    private function buildProperties(): array
    {
        // ToolPropertyクラスを使用してパラメータの型・必須属性を定義
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
            // 契約IDの形式バリデーション
            $this->validateOrderId($orderId);
            
            // ドメインサービスを呼び出して契約情報を取得
            $order = $this->orderService->getOrderById($orderId);
            
            // 該当する契約が存在しない場合のレスポンス
            if (!$order) {
                return json_encode(
                    ['status' => 'error', 'message' => '契約が見つかりませんでした'],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
            }

            // 正常系のレスポンス（AIが利用しやすい形式に整形）
            return json_encode([
                'id' => $order->getId(),
                'status' => $order->getStatus(),
                'total' => $order->getTotal(),
                'items' => $order->getItemsSummary()
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        } catch (InvalidArgumentException $e) {
            // パラメータバリデーションエラーの場合
            return json_encode(
                ['status' => 'error', 'message' => $e->getMessage()],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\Exception $e) {
            // その他の予期せぬエラー（内部エラーとして処理）
            return json_encode(
                ['status' => 'error', 'message' => '検索サービスは一時的に利用できません'],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }
    }

    /**
     * 契約IDの形式バリデーション
     * 指定されたフォーマットに一致しない場合は例外をスローする
     * @param string $orderId 検証対象の契約ID
     * @throws InvalidArgumentException バリデーションエラー時にスロー
     */
    private function validateOrderId(string $orderId): void
    {
        $orderId = trim($orderId);
        // 空文字のチェック
        if ($orderId === '') {
            throw new InvalidArgumentException('「order_id」は空文字列にすることができません');
        }

        // 正規表現による形式チェック（例: ORD-2023-001）
        if (!preg_match('/^ORD-\d{4}-\d{3}$/', $orderId)) {
            throw new InvalidArgumentException('「order_id」の形式が不正です。例：ORD-2023-001');
        }
    }

    /**
     * 親クラスのexecuteメソッドをオーバーライド
     * パラメータ不足やコールバック未設定の例外をキャッチし、業務用メッセージに変換する
     */
    public function execute(): void
    {
        try {
            parent::execute();
        } catch (MissingCallbackParameter | ToolCallableNotSet $e) {
            // 親クラスからの例外をキャッチし、JSON形式のエラーレスポンスを設定
            $this->setResult(json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}