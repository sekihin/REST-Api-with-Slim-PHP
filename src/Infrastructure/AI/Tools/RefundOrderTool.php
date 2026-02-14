<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use Neuron\Tools\Tool;
use App\Domain\Order\OrderService;
use Psr\Log\LoggerInterface;

/**
 * 返金申請ツール (RefundOrderTool)
 * * AIエージェントがユーザーに代わって注文の返金プロセスを開始するためのツールです。
 * * 注意: 金銭に関わる操作のため、実行時には監査ログの記録や厳格なエラーハンドリングが行われます。
 */
class RefundOrderTool extends Tool
{
    /** @var OrderService 注文関連のビジネスロジックサービス */
    private OrderService $orderService;
    
    /** @var LoggerInterface ログ出力用インターフェース（監査ログ記録用） */
    private LoggerInterface $logger;

    /**
     * コンストラクタ
     * * @param OrderService $orderService 返金処理を実行するドメインサービス
     * * @param LoggerInterface $logger 操作ログを記録するロガー
     */
    public function __construct(
        OrderService $orderService,
        LoggerInterface $logger
    ) {
        $this->orderService = $orderService;
        $this->logger = $logger;
    }

    /** * ツールの識別名 */
    protected string $name = 'refund_order';

    /** * ツールの説明
     * * AIに対する指示書です。
     * * 内容（訳）: "注文の返金申請を開始します。注意: 注文ステータスが返金可能であることを確認した後にのみ呼び出してください。"
     */
    protected string $description = '注文の返金申請を開始します。注意: 注文ステータスが返金可能であることを確認した後にのみ呼び出してください。';

    /**
     * パラメータのJSON Schema定義
     * * 返金処理に必要な「注文ID」と「理由」をAIに要求します。
     */
    protected array $parameters = [
        'type' => 'object',
        'properties' => [
            'order_id' => [
                'type' => 'string',
                // 説明: 返金対象の注文番号
                'description' => '返金対象の注文番号'
            ],
            'reason' => [
                'type' => 'string',
                // 説明: ユーザーが提示した返金理由（例: "商品破損" や "誤購入"）
                'description' => 'ユーザーが提示した返金理由（例: "商品破損" や "誤購入"）'
            ]
        ],
        'required' => ['order_id', 'reason'] // 両方のパラメータを必須とする
    ];

    /**
     * ツールの実行ロジック
     * * @param array $args AIから渡された引数
     * @return string AIに返すJSON形式の実行結果
     */
    public function execute(array $args): string
    {
        // 引数の取得とデフォルト値の設定
        $orderId = $args['order_id'] ?? '';
        $reason = $args['reason'] ?? '理由なし'; // "理由なし"

        // バリデーション: 注文IDがない場合は即エラー
        if (empty($orderId)) {
            return json_encode(['error' => '注文番号は必須です。']); // エラー: 注文番号は必須です
        }

        try {
            // 1. 監査ログの記録 (Audit Logging)
            // AIが金銭操作を試みたことをシステムログに残します。
            // 'source' => 'AI_AGENT' とすることで、人間の操作と区別可能にします。
            $this->logger->info("AI Agent attempting refund for Order: {$orderId}", [
                'reason' => $reason,
                'source' => 'AI_AGENT'
            ]);

            // 2. ビジネスサービスによる返金処理の実行
            // Service層で以下のチェックが行われます：
            // - 注文は存在するか？
            // - ステータスは返金可能か（出荷済み、配達済みなど）？
            // - 返金金額は正しいか？
            $refundResult = $this->orderService->processRefund($orderId, $reason);

            // 3. 処理結果に応じたレスポンス分岐
            if ($refundResult['success']) {
                // 成功時: 返金IDと金額を含むメッセージを返す
                return json_encode([
                    'status' => 'success',
                    'message' => '返金申請を受け付けました。処理中です。', // "返金申請を受け付けました。処理中です。"
                    'refund_id' => $refundResult['refund_id'],
                    'amount' => $refundResult['amount']
                ], JSON_UNESCAPED_UNICODE);
            } else {
                // 失敗時（業務ルールによる拒否）:
                // 例: "7日間の返品期間を過ぎています" などの理由を返す
                return json_encode([
                    'status' => 'failed',
                    'reason' => $refundResult['message']
                ], JSON_UNESCAPED_UNICODE);
            }

        } catch (\DomainException $e) {
            // ドメイン例外の捕捉
            // 業務ルール上の矛盾（例: 既に返金済みの注文に対して再度返金を要求したなど）
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            // システム例外（予期せぬエラー）の捕捉
            // データベース接続エラーなどはここでキャッチし、ログには詳細を残すが、
            // ユーザー（およびAI）には「有人対応」を促すメッセージを返す。
            $this->logger->error("Refund failed for Order {$orderId}: " . $e->getMessage());
            
            return json_encode(['error' => 'システムが混雑しています。有人対応へ切り替えてください。']); // "システムが混雑しています。有人対応へ切り替えてください"
        }
    }
}