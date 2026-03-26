<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\AI\Factories\AgentFactory;
use App\Domain\Knowledge\KnowledgeBaseService;
use App\App\CustomResponse;

/**
 * RAGコントローラー
 * クライアントからのRAGリクエスト (POST /api/rag) および
 * /agent/doubao/step4 (RAG Workflow) を処理するエンドポイントです。
 */
class RagController
{
    public function __construct(
        private AgentFactory $agentFactory,
        private KnowledgeBaseService $knowledgeBaseService
    ) {
    }

    /**
     * GET/POST /agent/doubao/step4
     * RAG Workflow:
     *   1. User Message
     *   2. Pre-Process Node (クエリ正規化)
     *   3. Retrieval Node (KnowledgeBaseService でベクトル検索)
     *   4. Post-Process Node (検索結果のテキスト整形)
     *   5. Enrich Instructions Node (RouterAgent のシステムプロンプトへ注入)
     *   6. Chat Node (RouterAgent による応答生成)
     *   7. Tool Node (RouterAgent 内で LookupOrder / CheckDelivery / SearchFaq ツール呼び出し)
     *   8. Assistant Message (HTTP レスポンス JSON としてクライアントへ返却)
     */
    public function rag(Request $request, Response $response): Response
    {
        // カスタムレスポンスオブジェクトを初期化（JSONレスポンスの統一フォーマット用）
        $customResponse = new CustomResponse();
        
        // リクエストからクエリパラメータとボディパラメータを取得
        $queryParams = $request->getQueryParams();
        $bodyParams = $request->getParsedBody() ?? [];

        // Step 1: ユーザーからのメッセージを取得（ボディ優先、クエリパラメータをフォールバック）
        $userMessage = $bodyParams['message'] ?? $queryParams['message'] ?? null;
        
        // メッセージが空の場合、400エラーを返却（必須パラメータのバリデーション）
        if ($userMessage === null || $userMessage === '') {
            return $customResponse->withJson(['status' => 'error', 'message' => 'No message provided'], 400, JSON_UNESCAPED_UNICODE);
        }

        // Step 2: Pre-Process Node - クエリ正規化処理
        // 全角/半角を含む余分な空白を統一し、前後の空白をトリム（検索精度向上のため）
        $preprocessedMessage = trim(preg_replace('/\s+/u', ' ', (string) $userMessage));
        
        // 正規化後も空文字列の場合、400エラーを返却（意味のないクエリの排除）
        if ($preprocessedMessage === '') {
            return $customResponse->withJson(['status' => 'error', 'message' => 'Message is empty after preprocessing'], 400, JSON_UNESCAPED_UNICODE);
        }

        // ナレッジベースから取得したコンテキストを格納する変数を初期化
        $knowledgeContext = '';
        
        try {
            // Step 3: Retrieval Node - KnowledgeBaseServiceでベクトル検索を実行
            // 正規化済みクエリで類似度の高い上位3件のドキュメントを取得
            $kbResults = $this->knowledgeBaseService->searchDocuments($preprocessedMessage, 3);
            
            // 検索結果が存在する場合、テキスト整形を実行
            if (!empty($kbResults)) {
                // Step 4: Post-Process Node - 検索結果のテキスト整形
                // Agentに渡すためのコンテキスト形式を構築
                $lines = [];
                $lines[] = "以下は社内ナレッジベースから取得した関連情報です。内容を参考にして、ユーザーの質問に一貫性のある回答を行ってください。";
                
                // 各ドキュメントにランク/タイトル/セクション/内容を追加
                foreach ($kbResults as $idx => $doc) {
                    $rank = $idx + 1; // 1から始まるランク番号
                    $title = $doc['title'] ?? '不明'; // タイトルがない場合は「不明」を補完
                    $section = $doc['section'] ?? '不明'; // セクションがない場合は「不明」を補完
                    $content = $doc['content'] ?? ''; // 内容がない場合は空文字を補完
                    
                    $lines[] = "[文書 {$rank}: {$title} ({$section})]";
                    $lines[] = $content;
                    $lines[] = ""; // ドキュメント間の区切りとして空行を追加
                }
                
                // 整形した行を改行で連結してコンテキストを生成
                $knowledgeContext = implode("\n", $lines);
            }
        } catch (\Throwable $e) {
            // ナレッジ検索のエラーは致命的ではないため、ログのみで処理を継続
            // （必要に応じてログ出力処理を追加することを推奨）
            // error_log('KnowledgeBase search error: ' . $e->getMessage());
        }

        try {
            // Step 5: Enrich Instructions Node - RouterAgentのインスタンスを作成
            // ゲストユーザー用のRouterAgentを生成（権限制御対応）
            $agent = $this->agentFactory->createRouterAgent('guest');
            
            // ナレッジコンテキストが存在する場合、Agentのシステムプロンプトに注入
            if ($knowledgeContext !== '') {
                $agent->withKnowledgeContext($knowledgeContext);
            }

            // Step 6: Chat Node - RouterAgentによる応答生成を実行
            // Step 7: Tool Node - Agent内部で必要に応じてLookupOrder/CheckDelivery/SearchFaqツールを呼び出し
            $result = $agent->run($preprocessedMessage);
            
            // Agentから返却された応答コンテンツを取得
            $replyContent = $result->getContent();
        } catch (\Throwable $e) {
            // Agent実行中のエラーは致命的なため、500エラーを返却
            return $customResponse->withJson([
                'status' => 'error',
                'message' => 'エラー: ' . $e->getMessage(),
            ], 500, JSON_UNESCAPED_UNICODE);
        }

        // Step 8: Assistant Message - クライアントへJSONレスポンスを返却
        return $customResponse->withJson([
            'status' => 'ok', // 処理成功ステータス
            'reply' => $replyContent, // Agentからの応答内容
            'preprocessed_message' => $preprocessedMessage, // 正規化後のユーザークエリ（デバッグ用）
            'knowledge_used' => $knowledgeContext !== '', // ナレッジベースを使用したか否かのフラグ
        ], 200, JSON_UNESCAPED_UNICODE); // UTF-8文字をエスケープせずに返却
    }
}