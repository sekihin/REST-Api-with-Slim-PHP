<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Domain\Order\OrderService;

/**
 * 注文管理コントローラー
 * * 注文に関するHTTPリクエスト (GET, POST) を処理し、ビジネスロジックへ橋渡しを行います。
 * * フロントエンドやモバイルアプリからの直接的な操作を受け付けます。
 */
class OrderController
{
    /** @var OrderService 注文ドメインサービス */
    private OrderService $orderService;

    /**
     * コンストラクタ
     * * @param OrderService $orderService 依存性注入されたサービス
     */
    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * 注文詳細の取得
     * * GET /api/orders/{id}
     * *
     * * @param Request  $request
     * * @param Response $response
     * * @param array    $args     URLプレースホルダ引数 (['id' => '...'])
     * * @return Response
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        // URLパラメータから注文IDを取得
        $orderId = $args['id'];
        
        // サービス層を通じてデータを検索
        $order = $this->orderService->getOrderById($orderId);

        // 注文が存在しない場合の404エラーハンドリング
        if (!$order) {
            $response->getBody()->write(json_encode(['error' => 'Order not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // レスポンスの整形
        // エンティティ自身が持つ変換メソッド (getItemsSummary) を再利用することで、
        // AIツールへの出力形式とAPIの出力形式の一貫性を保ちやすくなります。
        $payload = json_encode([
            'id' => $order->getId(),
            'status' => $order->getStatus(),
            'total' => $order->getTotal(),
            'items' => $order->getItemsSummary() // 商品リストの要約
        ]);

        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * 返金申請API
     * * POST /api/orders/{id}/refund
     * *
     * * このエンドポイントは、AIエージェントの `RefundOrderTool` と同じビジネスロジックを実行します。
     * * したがって、バリデーションルールや監査ログの記録も同様に行われます。
     */
    public function refund(Request $request, Response $response, array $args): Response
    {
        $orderId = $args['id'];
        $body = $request->getParsedBody();
        // 返金理由の取得（API経由の場合はデフォルト値を設定）
        $reason = $body['reason'] ?? 'Requested via API';

        try {
            // Service内の processRefund ロジックを再利用
            // ここがアーキテクチャの要点です。AIツールとWeb APIが同じメソッドを呼ぶため、
            // 「AIなら返金できるが、Webからはできない」といった不整合を防げます。
            $result = $this->orderService->processRefund($orderId, $reason);

            if (!$result['success']) {
                // ビジネスルール違反（例：期限切れ）による失敗は 400 Bad Request
                $response->getBody()->write(json_encode(['error' => $result['message']]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // 成功時は結果をそのまま返す
            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            // システムエラー時は 500 Internal Server Error
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}