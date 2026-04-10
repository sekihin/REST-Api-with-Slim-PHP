<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\AI\Factories\AgentFactory;
use App\Domain\Knowledge\KnowledgeBaseService;
use App\Domain\Memory\ElasticsearchMemoryService;
use App\App\CustomResponse;
use GuzzleHttp\Promise\Utils;
use Twig\Loader\ArrayLoader;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Sandbox\SecurityPolicy;

/**
 * RAGコントローラー
 * クライアントからのRAGリクエスト (POST /api/rag) および
 * /agent/doubao/step4 (RAG Workflow) を処理するエンドポイントです。
 */
class RagController
{
    public function __construct(
        private AgentFactory $agentFactory,
        private KnowledgeBaseService $knowledgeBaseService,
        private ElasticsearchMemoryService $userMemoryService
    ) {
    }

    /**
     * GET/POST /agent/doubao/step4
     * RAG & Memory Workflow (Hybrid Agent):
     * 1. User Message (ユーザー入力とID取得)
     * 2. Pre-Process Node (クエリ正規化)
     * 3a. Memory Retrieval (UserMemoryService で長期記憶検索)
     * 3b. Knowledge Retrieval (KnowledgeBaseService でRAG検索)
     * 4. Post-Process Node (検索結果のテキスト整形)
     * 5. Enrich Instructions Node (ハイブリッドコンテキストと短期記憶をAgentへ注入)
     * 6. Chat Node (RouterAgent による応答生成)
     * 7. Tool Node (Agent内部でツール呼び出し)
     * 7.5 Memory Extraction (やり取りから新たな事実を長期記憶に保存)
     * 8. Assistant Message (HTTP レスポンス JSON として返却)
     */
    public function rag(Request $request, Response $response): Response
    {
        $customResponse = new CustomResponse();
        
        $queryParams = $request->getQueryParams();
        $bodyParams = $request->getParsedBody() ?? [];

        // Step 1: ユーザーからのメッセージと各種IDを取得
        $userMessage = $bodyParams['message'] ?? $queryParams['message'] ?? null;
        $filterDocType = $bodyParams['filter_doc_type'] ?? $queryParams['filter_doc_type'] ?? null;
        
        // 【追加】MemoryとHistoryを特定するためのユーザーIDとセッションID
        // ※ 実際のアプリでは認証情報(JWTやSession)から取得するのを推奨します
        $userId = $bodyParams['user_id'] ?? $queryParams['user_id'] ?? 'guest_user';
        $sessionId = $bodyParams['session_id'] ?? $queryParams['session_id'] ?? uniqid('sess_');
        
        if ($userMessage === null || $userMessage === '') {
            return $customResponse->withJson(['status' => 'error', 'message' => 'No message provided'], 400, JSON_UNESCAPED_UNICODE);
        }

        // Step 2: Pre-Process Node - クエリ正規化処理
        $preprocessedMessage = trim(preg_replace('/\s+/u', ' ', (string) $userMessage));
        
        if ($preprocessedMessage === '') {
            return $customResponse->withJson(['status' => 'error', 'message' => 'Message is empty after preprocessing'], 400, JSON_UNESCAPED_UNICODE);
        }

        // コンテキスト用変数の初期化
        $knowledgeContext = '';
        $memoryContext = '';
        
        try {
            // 【追加】Step 3a: Memory Retrieval - 長期記憶の検索
            // ※ コントローラーに $this->userMemoryService がDIされている想定
            if (isset($this->userMemoryService)) {
                $memoryContext = $this->userMemoryService->searchMemories($userId, $preprocessedMessage);
            }

            // Step 3b: Knowledge Retrieval - ハイブリッド検索を実行
            $kbResults = $this->knowledgeBaseService->searchDocuments($preprocessedMessage, $filterDocType, 3);
            
            if (!empty($kbResults)) {
                // Step 4: Post-Process Node - RAG検索結果のテキスト整形
                $lines = [];
                foreach ($kbResults as $idx => $doc) {
                    $rank = $idx + 1;
                    $title = $doc['title'] ?? '不明';
                    $docType = $doc['doc_type'] ?? '不明';
                    $content = $doc['content'] ?? '';
                    
                    $lines[] = "[文書 {$rank}: {$title} (Type: {$docType})]";
                    $lines[] = $content;
                    $lines[] = ""; 
                }
                $knowledgeContext = implode("\n", $lines);
            }
        } catch (\Throwable $e) {
            // 検索エラーはログのみで処理を継続
            // error_log('Search error: ' . $e->getMessage());
        }

        try {
            // Step 5: Enrich Instructions Node - RouterAgentのインスタンスを作成
            $agent = $this->agentFactory->createRouterAgent('guest');
            
            // 【変更】ハイブリッドコンテキスト (RAG + 長期記憶) を注入
            if (method_exists($agent, 'withHybridContext')) {
                $agent->withHybridContext($memoryContext, $knowledgeContext);
            }

            // Step 6 & 7: Chat Node & Tool Node - 応答生成を実行
            $result = $agent->reply($preprocessedMessage, $sessionId);
            $replyContent = $result->getContent();
            
            // ==============================================================
            // 📝 Twig Engine - テンプレートによるデータ書き換え (SSTI対策済み)
            // ==============================================================
            $isEmailTemplate = ($filterDocType === 'email_template') 
                            || (str_contains($knowledgeContext, 'Type: email_template'));

            if ($isEmailTemplate) {
                try {
                    $productionData = [
                        'user_name'       => 'テスト 太郎', // 実際のアプリではDB等から取得
                        'company_name'    => '株式会社サンプル',
                        'expiration_date' => date('Y年m月d日', strtotime('+30 days')),
                        'login_url'       => 'https://example.com/login',
                    ];

                    $policy = new SecurityPolicy([], [], [], [], []);
                    $sandbox = new SandboxExtension($policy, true);
                    $loader = new ArrayLoader(['agent_reply' => $replyContent]);
                    $twig = new Environment($loader);
                    $twig->addExtension($sandbox);

                    $replyContent = $twig->render('agent_reply', $productionData);
                } catch (\Throwable $e) {
                    // Twigの構文エラー時は元の文字列をそのままフォールバック
                }
            }
            
            // 🧠 Step 7.5: Memory Extraction - 新たな事実を長期記憶に保存
            // ※ 同期処理だとレスポンスが遅くなる場合があるため、本番環境では
            //    Laravel Job や Message Queue に投げる（非同期化する）のがベストです。
            if (isset($this->userMemoryService)) {
                $this->userMemoryService->add($userId, $preprocessedMessage, $replyContent);
            }
            
        } catch (\Throwable $e) {
            return $customResponse->withJson([
                'status' => 'error',
                'message' => 'エラー: ' . $e->getMessage(),
            ], 500, JSON_UNESCAPED_UNICODE);
        }

        // Step 8: Assistant Message - クライアントへJSONレスポンスを返却
        return $customResponse->withJson([
            'status' => 'ok',
            'reply' => $replyContent,
            'preprocessed_message' => $preprocessedMessage,
            'knowledge_used' => $knowledgeContext !== '',
            'memory_used' => trim($memoryContext) !== '', // デバッグ用: 記憶が使われたか
            'filter_applied' => $filterDocType,
            'session_id' => $sessionId, // クライアント側で次回の会話に引き継ぐため返却
        ], 200, JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET/POST /agent/doubao/step4
     * RAG & Memory Workflow (Hybrid Agent):
     * 1. User Message (ユーザー入力とID取得)
     * 2. Pre-Process Node (クエリ正規化)
     * 3a. Chat History Retrieval (RouterAgent で短期記憶検索)
     * 3b. Memory Retrieval (UserMemoryService で長期記憶検索)
     * 3c. Knowledge Retrieval (KnowledgeBaseService でRAG検索)
     * 4a. Twig Engine (テンプレートエンジンでテキスト整形)
     * 4b. Post-Process Node (検索結果のテキスト整形)
     * 5. Enrich Instructions Node (ハイブリッドコンテキストと短期記憶をAgentへ注入)
     * 6. Chat Node (RouterAgent による応答生成)
     * 7. Tool Node (Agent内部でツール呼び出し)
     * 7.5 Memory Extraction (やり取りから新たな事実を長期記憶に保存)
     * 8. Assistant Message (HTTP レスポンス JSON として返却)
     */
    public function ragAsync(Request $request, Response $response): Response
    {
        $customResponse = new CustomResponse();
        
        $queryParams = $request->getQueryParams();
        $bodyParams = $request->getParsedBody() ?? [];

        // Step 1: ユーザーからのメッセージと各種IDを取得
        $userMessage = $bodyParams['message'] ?? $queryParams['message'] ?? null;
        $filterDocType = $bodyParams['filter_doc_type'] ?? $queryParams['filter_doc_type'] ?? null;
        
        // 【追加】MemoryとHistoryを特定するためのユーザーIDとセッションID
        // ※ 実際のアプリでは認証情報(JWTやSession)から取得するのを推奨します
        $userId = $bodyParams['user_id'] ?? $queryParams['user_id'] ?? 'guest_user';
        $sessionId = $bodyParams['session_id'] ?? $queryParams['session_id'] ?? uniqid('sess_');
        
        if ($userMessage === null || $userMessage === '') {
            return $customResponse->withJson(['status' => 'error', 'message' => 'No message provided'], 400, JSON_UNESCAPED_UNICODE);
        }

        // Step 2: Pre-Process Node - クエリ正規化処理
        $preprocessedMessage = trim(preg_replace('/\s+/u', ' ', (string) $userMessage));
        
        if ($preprocessedMessage === '') {
            return $customResponse->withJson(['status' => 'error', 'message' => 'Message is empty after preprocessing'], 400, JSON_UNESCAPED_UNICODE);
        }

        // コンテキスト用変数の初期化
        $knowledgeContext = '';
        $memoryContext = '';
        $chatHistory = null;
        
        try {
            // ==============================================================
            // 🚀 3つのデータソースからコンテキストを並行取得
            // ==============================================================

            // 1. [HTTP非同期] ChromaDBから長期記憶の検索リクエストを送信 (Waitしない)
            $memoryPromise = isset($this->userMemoryService) 
                ? $this->userMemoryService->searchMemoriesAsync($userId, $preprocessedMessage)
                : \GuzzleHttp\Promise\Create::promiseFor(''); // ない場合は空のPromise

            // 2. [HTTP非同期] ElasticsearchからRAGの検索リクエストを送信 (Waitしない)
            $ragPromise = $this->knowledgeBaseService->searchDocumentsAsync($preprocessedMessage, $filterDocType, 3);

            // 3. [同期/爆速] HTTP通信の返事を待っている間に、Redisから短期記憶(履歴)を取得
            // ※ Redisは別プロセスへのTCP通信ですが、1ms以下で終わるためここで同期取得します
            $agent = $this->agentFactory->createRouterAgent('guest');
            if (method_exists($agent, 'chatHistoryForSession')) {
                $chatHistory = $agent->chatHistoryForSession($sessionId);
            }

            // 4. [待機] HTTP非同期リクエスト(ChromaとElasticsearch)が両方完了するまで待つ
            // これにより、遅い方のAPIのレスポンス時間だけで両方のデータが揃います。
            $results = Utils::all([
                'memory' => $memoryPromise,
                'rag'    => $ragPromise,
            ])->wait();

            // 結果を変数に格納
            $memoryContext = $results['memory'];
            $knowledgeContext = $results['rag'];

        } catch (\Throwable $e) {
            // エラーハンドリング
            // error_log('Concurrent Context Fetch Error: ' . $e->getMessage());
        }

        // ==============================================================
        // 🤖 エージェントへ統合して実行
        // ==============================================================

        try {
            // 5. Enrich Instructions Node - ハイブリッドコンテキスト (RAG + 長期記憶) を注入
            if (method_exists($agent, 'withHybridContext')) {
                $agent->withHybridContext($memoryContext, $knowledgeContext);
            }

            // 6. Chat Node - 応答生成を実行
            $result = $agent->reply($preprocessedMessage, $sessionId);
            $replyContent = $result->getContent();
            
            // ==============================================================
            // 📝 4a. Twig Engine - テンプレートによるデータ書き換え (SSTI対策済み)
            // ==============================================================
            $isEmailTemplate = ($filterDocType === 'email_template') 
                            || (str_contains($knowledgeContext, 'Type: email_template'));

            if ($isEmailTemplate) {
                try {
                    $productionData = [
                        'user_name'       => 'テスト 太郎', // 実際のアプリではDB等から取得
                        'company_name'    => '株式会社サンプル',
                        'expiration_date' => date('Y年m月d日', strtotime('+30 days')),
                        'login_url'       => 'https://example.com/login',
                    ];

                    $policy = new SecurityPolicy([], [], [], [], []);
                    $sandbox = new SandboxExtension($policy, true);
                    $loader = new ArrayLoader(['agent_reply' => $replyContent]);
                    $twig = new Environment($loader);
                    $twig->addExtension($sandbox);

                    $replyContent = $twig->render('agent_reply', $productionData);
                } catch (\Throwable $e) {
                    // Twigの構文エラー時は元の文字列をそのままフォールバック
                }
            }

            // 7.5 Memory Extraction - 新たな事実を長期記憶に保存
            // ※ 同期処理だとレスポンスが遅くなる場合があるため、本番環境では
            //    Laravel Job や Message Queue に投げる（非同期化する）のがベストです。
            if (isset($this->userMemoryService)) {
                $this->userMemoryService->add($userId, $preprocessedMessage, $replyContent);
            }
            
        } catch (\Throwable $e) {
            return $customResponse->withJson([
                'status' => 'error',
                'message' => 'エラー: ' . $e->getMessage(),
            ], 500, JSON_UNESCAPED_UNICODE);
        }

        // Step 8: Assistant Message - クライアントへJSONレスポンスを返却
        return $customResponse->withJson([
            'status' => 'ok',
            'reply' => $replyContent,
            'preprocessed_message' => $preprocessedMessage,
            'knowledge_used' => $knowledgeContext !== '',
            'memory_used' => trim($memoryContext) !== '', // デバッグ用: 記憶が使われたか
            'filter_applied' => $filterDocType,
            'session_id' => $sessionId, // クライアント側で次回の会話に引き継ぐため返却
        ], 200, JSON_UNESCAPED_UNICODE);
    }    
}