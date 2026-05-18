<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\AI\Factories\AgentFactory;
use App\Domain\Knowledge\KnowledgeBaseService;
use App\Domain\Memory\ElasticsearchMemoryService;
use App\App\CustomResponse;
use App\Common\PerfTrace;
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
        $requestStart = PerfTrace::now();
        PerfTrace::log('RequestStart', $requestStart, [
            'endpoint' => 'rag',
            'method' => $request->getMethod(),
        ]);

        $customResponse = new CustomResponse();
        
        $queryParams = $request->getQueryParams();
        $bodyParams = $request->getParsedBody() ?? [];

        // Step 1: ユーザーからのメッセージと各種IDを取得
        $tInput = PerfTrace::now();
        $userMessage = $bodyParams['message'] ?? $queryParams['message'] ?? null;
        $filterDocType = $bodyParams['filter_doc_type'] ?? $queryParams['filter_doc_type'] ?? null;
        
        // 【追加】MemoryとHistoryを特定するためのユーザーIDとセッションID
        // ※ 実際のアプリでは認証情報(JWTやSession)から取得するのを推奨します
        $userId = $bodyParams['user_id'] ?? $queryParams['user_id'] ?? 'guest_user';
        $sessionId = $bodyParams['session_id'] ?? $queryParams['session_id'] ?? uniqid('sess_');
        
        if ($userMessage === null || $userMessage === '') {
            PerfTrace::log('InputValidation', $tInput, ['status' => 'error', 'reason' => 'no_message']);
            return $customResponse->withJson(['status' => 'error', 'message' => 'No message provided'], 400, JSON_UNESCAPED_UNICODE);
        }
        PerfTrace::log('InputValidation', $tInput, [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'message_length' => strlen((string) $userMessage),
        ]);

        // Step 2: Pre-Process Node - クエリ正規化処理
        $tPreprocess = PerfTrace::now();
        $preprocessedMessage = trim(preg_replace('/\s+/u', ' ', (string) $userMessage));
        PerfTrace::log('QueryPreprocess', $tPreprocess, [
            'query_length' => strlen($preprocessedMessage),
            'filter_doc_type' => $filterDocType ?? 'none',
        ]);
        
        if ($preprocessedMessage === '') {
            return $customResponse->withJson(['status' => 'error', 'message' => 'Message is empty after preprocessing'], 400, JSON_UNESCAPED_UNICODE);
        }

        // コンテキスト用変数の初期化
        $knowledgeContext = '';
        $memoryContext = '';
        
        $tContext = PerfTrace::now();
        try {
            // 【追加】Step 3a: Memory Retrieval - 長期記憶の検索
            // ※ コントローラーに $this->userMemoryService がDIされている想定
            if (isset($this->userMemoryService)) {
                $tMemory = PerfTrace::now();
                $memoryContext = $this->userMemoryService->searchMemories($userId, $preprocessedMessage);
                PerfTrace::log('MemoryRetrieval', $tMemory, [
                    'user_id' => $userId,
                    'query_length' => strlen($preprocessedMessage),
                    'context_length' => strlen($memoryContext),
                ]);
            }

            // Step 3b: Knowledge Retrieval - ハイブリッド検索を実行
            $tVectorSearch = PerfTrace::now();
            $kbResults = $this->knowledgeBaseService->searchDocuments($preprocessedMessage, $filterDocType, 3);
            PerfTrace::log('VectorSearch', $tVectorSearch, [
                'query_length' => strlen($preprocessedMessage),
                'top_k' => 3,
                'hits' => count($kbResults),
            ]);
            
            if (!empty($kbResults)) {
                // Step 4: Post-Process Node - RAG検索結果のテキスト整形
                $tPostProcess = PerfTrace::now();
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
                PerfTrace::log('PostProcess', $tPostProcess, [
                    'doc_count' => count($kbResults),
                    'context_length' => strlen($knowledgeContext),
                ]);
            }
        } catch (\Throwable $e) {
            PerfTrace::log('ContextRetrieval', $tContext, [
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);
        }

        try {
            // Step 5: Enrich Instructions Node - RouterAgentのインスタンスを作成
            $tEnrich = PerfTrace::now();
            $agent = $this->agentFactory->createRouterAgent('guest');
            
            // 【変更】ハイブリッドコンテキスト (RAG + 長期記憶) を注入
            if (method_exists($agent, 'withHybridContext')) {
                $agent->withHybridContext($memoryContext, $knowledgeContext);
            }
            PerfTrace::log('EnrichInstructions', $tEnrich, [
                'memory_context_length' => strlen($memoryContext),
                'knowledge_context_length' => strlen($knowledgeContext),
            ]);

            // Step 6 & 7: Chat Node & Tool Node - 応答生成を実行
            $tLlm = PerfTrace::now();
            $result = $agent->reply($preprocessedMessage, $sessionId);
            $replyContent = $result->getContent();
            PerfTrace::log('LLMReply', $tLlm, [
                'session_id' => $sessionId,
                'reply_length' => strlen($replyContent),
            ]);
            
            // ==============================================================
            // 📝 Twig Engine - テンプレートによるデータ書き換え (SSTI対策済み)
            // ==============================================================
            $isEmailTemplate = ($filterDocType === 'email_template') 
                            || (str_contains($knowledgeContext, 'Type: email_template'));

            if ($isEmailTemplate) {
                $tTwig = PerfTrace::now();
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
                PerfTrace::log('TwigRender', $tTwig, ['reply_length' => strlen($replyContent)]);
            }
            
            // 🧠 Step 7.5: Memory Extraction - 新たな事実を長期記憶に保存
            // ※ 同期処理だとレスポンスが遅くなる場合があるため、本番環境では
            //    Laravel Job や Message Queue に投げる（非同期化する）のがベストです。
            if (isset($this->userMemoryService)) {
                $tMemoryAdd = PerfTrace::now();
                $this->userMemoryService->add($userId, $preprocessedMessage, $replyContent);
                PerfTrace::log('MemoryExtraction', $tMemoryAdd, ['user_id' => $userId]);
            }
            
        } catch (\Throwable $e) {
            PerfTrace::log('AgentPipeline', PerfTrace::now(), ['status' => 'error', 'error' => $e->getMessage()]);
            return $customResponse->withJson([
                'status' => 'error',
                'message' => 'エラー: ' . $e->getMessage(),
            ], 500, JSON_UNESCAPED_UNICODE);
        }

        // Step 8: Assistant Message - クライアントへJSONレスポンスを返却
        $tResponse = PerfTrace::now();
        $jsonResponse = $customResponse->withJson([
            'status' => 'ok',
            'reply' => $replyContent,
            'preprocessed_message' => $preprocessedMessage,
            'knowledge_used' => $knowledgeContext !== '',
            'memory_used' => trim($memoryContext) !== '', // デバッグ用: 記憶が使われたか
            'filter_applied' => $filterDocType,
            'session_id' => $sessionId, // クライアント側で次回の会話に引き継ぐため返却
        ], 200, JSON_UNESCAPED_UNICODE);
        PerfTrace::log('ResponseAssembly', $tResponse, [
            'reply_length' => strlen($replyContent),
            'knowledge_used' => $knowledgeContext !== '' ? 'yes' : 'no',
            'memory_used' => trim($memoryContext) !== '' ? 'yes' : 'no',
        ]);
        PerfTrace::log('RequestTotal', $requestStart, ['endpoint' => 'rag', 'status' => 'ok']);

        return $jsonResponse;
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
        $requestStart = PerfTrace::now();
        PerfTrace::log('RequestStart', $requestStart, [
            'endpoint' => 'ragAsync',
            'method' => $request->getMethod(),
        ]);

        $customResponse = new CustomResponse();
        
        $queryParams = $request->getQueryParams();
        $bodyParams = $request->getParsedBody() ?? [];

        // Step 1: ユーザーからのメッセージと各種IDを取得
        $tInput = PerfTrace::now();
        $userMessage = $bodyParams['message'] ?? $queryParams['message'] ?? null;
        $filterDocType = $bodyParams['filter_doc_type'] ?? $queryParams['filter_doc_type'] ?? null;
        
        // 【追加】MemoryとHistoryを特定するためのユーザーIDとセッションID
        // ※ 実際のアプリでは認証情報(JWTやSession)から取得するのを推奨します
        $userId = $bodyParams['user_id'] ?? $queryParams['user_id'] ?? 'guest_user';
        $sessionId = $bodyParams['session_id'] ?? $queryParams['session_id'] ?? uniqid('sess_');
        
        if ($userMessage === null || $userMessage === '') {
            PerfTrace::log('InputValidation', $tInput, ['status' => 'error', 'reason' => 'no_message']);
            return $customResponse->withJson(['status' => 'error', 'message' => 'No message provided'], 400, JSON_UNESCAPED_UNICODE);
        }
        PerfTrace::log('InputValidation', $tInput, [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'message_length' => strlen((string) $userMessage),
        ]);

        // Step 2: Pre-Process Node - クエリ正規化処理
        $tPreprocess = PerfTrace::now();
        $preprocessedMessage = trim(preg_replace('/\s+/u', ' ', (string) $userMessage));
        PerfTrace::log('QueryPreprocess', $tPreprocess, [
            'query_length' => strlen($preprocessedMessage),
            'filter_doc_type' => $filterDocType ?? 'none',
        ]);
        
        if ($preprocessedMessage === '') {
            return $customResponse->withJson(['status' => 'error', 'message' => 'Message is empty after preprocessing'], 400, JSON_UNESCAPED_UNICODE);
        }

        // コンテキスト用変数の初期化
        $knowledgeContext = '';
        $memoryContext = '';
        $chatHistory = null;
        
        $tContext = PerfTrace::now();
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
            $tRedis = PerfTrace::now();
            $agent = $this->agentFactory->createRouterAgent('guest');
            if (method_exists($agent, 'chatHistoryForSession')) {
                $chatHistory = $agent->chatHistoryForSession($sessionId);
            }
            PerfTrace::log('ChatHistoryRetrieval', $tRedis, ['session_id' => $sessionId]);

            // 4. [待機] HTTP非同期リクエスト(ChromaとElasticsearch)が両方完了するまで待つ
            // これにより、遅い方のAPIのレスポンス時間だけで両方のデータが揃います。
            $tParallel = PerfTrace::now();
            $results = Utils::all([
                'memory' => $memoryPromise,
                'rag'    => $ragPromise,
            ])->wait();

            // 結果を変数に格納
            $memoryContext = $results['memory'];
            $knowledgeContext = $results['rag'];
            PerfTrace::log('ParallelContextFetch', $tParallel, [
                'memory_context_length' => strlen((string) $memoryContext),
                'knowledge_context_length' => strlen((string) $knowledgeContext),
            ]);
            PerfTrace::log('ConcurrentContextRetrieval', $tContext, [
                'query_length' => strlen($preprocessedMessage),
            ]);

        } catch (\Throwable $e) {
            PerfTrace::log('ConcurrentContextRetrieval', $tContext, [
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);
        }

        // ==============================================================
        // 🤖 エージェントへ統合して実行
        // ==============================================================

        try {
            // 5. Enrich Instructions Node - ハイブリッドコンテキスト (RAG + 長期記憶) を注入
            $tEnrich = PerfTrace::now();
            if (method_exists($agent, 'withHybridContext')) {
                $agent->withHybridContext($memoryContext, $knowledgeContext);
            }
            PerfTrace::log('EnrichInstructions', $tEnrich, [
                'memory_context_length' => strlen((string) $memoryContext),
                'knowledge_context_length' => strlen((string) $knowledgeContext),
            ]);

            // 6. Chat Node - 応答生成を実行
            $tLlm = PerfTrace::now();
            $result = $agent->reply($preprocessedMessage, $sessionId);
            $replyContent = $result->getContent();
            PerfTrace::log('LLMReply', $tLlm, [
                'session_id' => $sessionId,
                'reply_length' => strlen($replyContent),
            ]);
            
            // ==============================================================
            // 📝 4a. Twig Engine - テンプレートによるデータ書き換え (SSTI対策済み)
            // ==============================================================
            $isEmailTemplate = ($filterDocType === 'email_template') 
                            || (str_contains($knowledgeContext, 'Type: email_template'));

            if ($isEmailTemplate) {
                $tTwig = PerfTrace::now();
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
                PerfTrace::log('TwigRender', $tTwig, ['reply_length' => strlen($replyContent)]);
            }

            // 7.5 Memory Extraction - 新たな事実を長期記憶に保存
            // ※ 同期処理だとレスポンスが遅くなる場合があるため、本番環境では
            //    Laravel Job や Message Queue に投げる（非同期化する）のがベストです。
            if (isset($this->userMemoryService)) {
                $tMemoryAdd = PerfTrace::now();
                $this->userMemoryService->add($userId, $preprocessedMessage, $replyContent);
                PerfTrace::log('MemoryExtraction', $tMemoryAdd, ['user_id' => $userId]);
            }
            
        } catch (\Throwable $e) {
            PerfTrace::log('AgentPipeline', PerfTrace::now(), ['status' => 'error', 'error' => $e->getMessage()]);
            return $customResponse->withJson([
                'status' => 'error',
                'message' => 'エラー: ' . $e->getMessage(),
            ], 500, JSON_UNESCAPED_UNICODE);
        }

        // Step 8: Assistant Message - クライアントへJSONレスポンスを返却
        $tResponse = PerfTrace::now();
        $jsonResponse = $customResponse->withJson([
            'status' => 'ok',
            'reply' => $replyContent,
            'preprocessed_message' => $preprocessedMessage,
            'knowledge_used' => $knowledgeContext !== '',
            'memory_used' => trim($memoryContext) !== '', // デバッグ用: 記憶が使われたか
            'filter_applied' => $filterDocType,
            'session_id' => $sessionId, // クライアント側で次回の会話に引き継ぐため返却
        ], 200, JSON_UNESCAPED_UNICODE);
        PerfTrace::log('ResponseAssembly', $tResponse, [
            'reply_length' => strlen($replyContent),
            'knowledge_used' => $knowledgeContext !== '' ? 'yes' : 'no',
            'memory_used' => trim((string) $memoryContext) !== '' ? 'yes' : 'no',
        ]);
        PerfTrace::log('RequestTotal', $requestStart, ['endpoint' => 'ragAsync', 'status' => 'ok']);

        return $jsonResponse;
    }    
}