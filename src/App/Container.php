<?php

declare(strict_types=1);

use Pimple\Container;
use Pimple\Psr11\Container as Psr11Container;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use App\App\ResponseFactory as CustomResponseFactory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Bayfront\MonologPDO\PDOHandler; 
use App\Infrastructure\Logging\DatabaseHandler;
use App\Domain\Order\OrderRepository;
use App\Domain\Order\OrderService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Knowledge\KnowledgeBaseService;
use App\Domain\Memory\ElasticsearchMemoryService;
use App\Domain\Software\SoftwareService;
use App\Infrastructure\Persistence\InMemoryOrderRepository;
use App\Infrastructure\AI\Agents\RouterAgent;
use App\Infrastructure\AI\Factories\AgentFactory;
use App\Infrastructure\AI\Tools\LookupOrderTool;
use App\Infrastructure\AI\Tools\CheckDeliveryTool;
use App\Workflows\CheckDelivery\CheckDeliveryWorkflow;
use App\Workflows\CheckDelivery\Nodes\ValidateInputNode;
use App\Workflows\CheckDelivery\Nodes\FetchOrderNode;
use App\Workflows\CheckDelivery\Nodes\EvaluateDeliveryNode;
use App\Infrastructure\AI\Tools\CheckInventoryTool;
use App\Infrastructure\AI\Tools\RefundOrderTool;
use App\Infrastructure\AI\Tools\SearchFaqTool;
use App\Infrastructure\AI\Tools\GetInstallerTool;
use App\Infrastructure\AI\Tools\SearchInstallerTool;
use App\Infrastructure\AI\Agents\GetInstallerAgent;
use App\Workflows\GetInstaller\GetInstallerWorkflow;
use App\Workflows\GetInstaller\Nodes\ProcessInstallerQueryNode;
use App\Infrastructure\External\GeminiProvider;
use App\Infrastructure\External\DeepSeekProvider;
use App\Infrastructure\External\DoubaoProvider;
use App\Infrastructure\External\DoubaoEmbeddingProvider;
use App\Infrastructure\External\GeminiEmbeddingProvider;
use App\Application\Controllers\ChatController;
use App\Application\Controllers\RagController;
use App\Application\Controllers\OrderController;
use App\Application\Controllers\InventoryController;
use App\Neuron\Agents\GeneralChatAgent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;
use Elastic\Elasticsearch\ClientBuilder;
use Psr\Log\LoggerInterface;
use Monolog\Logger; 
use Monolog\Handler\StreamHandler;

// Pimpleコンテナの初期化
$container = new Container();

// --- 1. 基本的なHTTP/レスポンスファクトリ (PSR-7/17) ---
// Webフレームワークの基盤となるリクエスト・レスポンス生成器です。

$container[Psr17Factory::class] = function ($c) {
    return new Psr17Factory();
};

$container[CustomResponseFactory::class] = function ($c) {
    return new CustomResponseFactory();
};

// スーパーグローバル変数 ($_SERVER, $_POST等) からPSR-7リクエストを作成するクリエイター
$container[ServerRequestCreator::class] = function ($c) {
    $psr17Factory = $c[Psr17Factory::class];
    return new ServerRequestCreator(
        $psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory
    );
};

// 現在のHTTPリクエストインスタンス
$container['request'] = function ($c) {
    return $c[ServerRequestCreator::class]->fromGlobals();
};

// インターフェースへのバインディング
$container[ResponseFactoryInterface::class] = function ($c) {
    return $c[CustomResponseFactory::class];
};

$container[StreamFactoryInterface::class] = function ($c) {
    return $c[Psr17Factory::class];
};

// --- 2. ロギング設定 (Monolog) ---
// DeepSeekProviderやRefundOrderToolが依存しているため、非常に重要です。

$container[LoggerInterface::class] = function ($c) {
    // チャンネル名 'app' でロガーを作成
    $logger = new Logger('app');
    
    // ハンドラー設定: 標準出力 (php://stdout) に書き込む
    // コンテナ化された環境 (Docker, Kubernetes) では、ログをファイルではなく標準出力に出し、
    // インフラ側 (Fluentd, CloudWatch等) で回収するのがベストプラクティスです。
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
    
    // 必要であれば、重大なエラーのみSlack通知やメール送信するハンドラーもここに追加できます。
    
    return $logger;
};

// --- 3. データベース接続 (PDO) ---

$container['db'] = function ($c) {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;port=%s;charset=utf8mb4',
        getenv('DB_HOST'), getenv('DB_NAME'), getenv('DB_PORT')
    );
    
    $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
    // エラーは例外として投げる (必須設定)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // データは連想配列で取得
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // セキュリティ対策: 静的プレースホルダを使用
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    return $pdo;
};

// DIコンテナにロガーとAgentを登録（セッションやユーザーごとの記憶を扱いやすくするため）
$container['logger'] = function ($c) {
    $logger = new Logger('chat');

    // DBへ保存（テーブル: c_logs）
    $logger->pushHandler(new DatabaseHandler($c['db'], 'c_logs', Logger::DEBUG));

    // リクエスト情報を自動付与（利用可能な場合）
    $logger->pushProcessor(function ($record) use ($c) {
        try {
            if (!isset($c['request'])) {
                return $record;
            }
            $req = $c['request'];

            $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = method_exists($req, 'getHeaderLine') ? $req->getHeaderLine('User-Agent') : null;
            $requestUri = method_exists($req, 'getUri') ? (string)$req->getUri() : null;

            if ($record instanceof \Monolog\LogRecord) {
                $ctx = $record->context;
                $ctx['remote_addr'] = $ctx['remote_addr'] ?? $remoteAddr;
                $ctx['user_agent'] = $ctx['user_agent'] ?? $userAgent;
                $ctx['request_uri'] = $ctx['request_uri'] ?? $requestUri;
                return $record->with(context: $ctx);
            }

            if (is_array($record)) {
                $record['context'] = $record['context'] ?? [];
                $record['context']['remote_addr'] = $record['context']['remote_addr'] ?? $remoteAddr;
                $record['context']['user_agent'] = $record['context']['user_agent'] ?? $userAgent;
                $record['context']['request_uri'] = $record['context']['request_uri'] ?? $requestUri;
                return $record;
            }
        } catch (\Throwable $e) {
            // ロガー自体の例外でアプリを落とさない
        }
        return $record;
    });

    return $logger;
};

// --- 4. ドメイン & インフラストラクチャ層 ---

// [リポジトリ層]
// 現状: インメモリ (テスト/プロトタイプ用)
// 将来: MySQLを使う場合はここを `return new MySQLOrderRepository($c['db']);` に変更するだけで、
// アプリケーション全体のデータ保存先が切り替わります。
$container[OrderRepository::class] = function ($c) {
    return new InMemoryOrderRepository();
};

// [サービス層]
// リポジトリの実装詳細を知らず、インターフェースのみに依存します。
$container[OrderService::class] = function ($c) {
    return new OrderService($c[OrderRepository::class]);
};

$container[InventoryService::class] = function ($c) {
    return new InventoryService(); // 将来的には InventoryRepository を注入
};

$container[OrderController::class] = function ($c) {
    return new OrderController($c[OrderService::class]);
};

$container[InventoryController::class] = function ($c) {
    return new InventoryController($c[InventoryService::class]);
};

// --- 5. AIツール (Tools) ---
// AIエージェントが使用する「手足」となる機能群です。
$container[GeneralChatAgent::class] = function ($c) {
    return new GeneralChatAgent($c['logger']);
};

// 契約検索ツール
$container[LookupOrderTool::class] = function ($c) {
    return new LookupOrderTool($c[OrderService::class]);
};

// 配送状況照会ワークフロー（CheckDeliveryTool 用）
$container[CheckDeliveryWorkflow::class] = function ($c) {
    return new CheckDeliveryWorkflow(
        new ValidateInputNode(),
        new FetchOrderNode($c[OrderService::class]),
        new EvaluateDeliveryNode()
    );
};

// 配送状況照会ツール（ルーターエージェント用）
$container[CheckDeliveryTool::class] = function ($c) {
    return new CheckDeliveryTool($c[CheckDeliveryWorkflow::class]);
};

// 在庫確認ツール
$container[CheckInventoryTool::class] = function ($c) {
    return new CheckInventoryTool($c[InventoryService::class]);
};

// 返金ツール (重要)
// 金銭操作を含むため、OrderServiceだけでなくLoggerInterfaceも注入し、
// 「誰が・いつ・なぜ」返金したかの監査ログを記録できるようにします。
$container[RefundOrderTool::class] = function ($c) {
    return new RefundOrderTool(
        $c[OrderService::class],
        $c[LoggerInterface::class] // 上記セクション2で定義したロガーが自動注入されます
    );
};

// マニュアル検索ツール (重要)
// Elasticsearch クライアントの登録
$container[\Elastic\Elasticsearch\Client::class] = function ($c) {
    return ClientBuilder::create()
        ->setHosts(['http://localhost:9200'])
        ->setRetries(3)
        ->build();
};

//埋込みプロバイダーの動的選択
$container['embedding_provider'] = function ($c) {
    $embeddingModel = getenv('EMBEDDING_MODEL') ?: 'doubao';
    
    switch (strtolower($embeddingModel)) {
        case 'gemini':
            $apiKey = getenv('GEMINI_API_KEY') ?: throw new \Exception('Missing GEMINI_API_KEY for embedding');
            return new GeminiEmbeddingProvider($apiKey);
        
        case 'doubao':
        default:
            $apiKey = getenv('DOBAO_API_KEY') ?: throw new \Exception('Missing DOBAO_API_KEY for embedding');
            return new DoubaoEmbeddingProvider($apiKey, $c[LoggerInterface::class]);
    }
};

// KnowledgeBaseService の登録
$container[KnowledgeBaseService::class] = function ($c) {
    return new KnowledgeBaseService(
        $c[\Elastic\Elasticsearch\Client::class],
        $c['embedding_provider']
    );
};

// ElasticsearchMemoryService の登録（RAG 長期記憶）
$container[ElasticsearchMemoryService::class] = function ($c) {
    return new ElasticsearchMemoryService(
        $c[\Elastic\Elasticsearch\Client::class],
        $c['embedding_provider']
    );
};

// SearchFaqTool の登録
$container[SearchFaqTool::class] = function ($c) {
    return new SearchFaqTool(
        $c[KnowledgeBaseService::class],
        $c[\Psr\Log\LoggerInterface::class]
    );
};

$container[SoftwareService::class] = function () {
    return new SoftwareService();
};

$container[SearchInstallerTool::class] = function ($c) {
    return new SearchInstallerTool($c[SoftwareService::class]);
};

// --- 6. AIエージェント & LLM (Brain) ---

// AIプロバイダーインターフェースの実装バインディング
// 環境変数 (LLM_PROVIDER) の値によって、インスタンス化するクラス（AIの脳）を動的に切り替えます。
// これにより、ベンダーロックインを防ぎ、コストや性能に応じて柔軟にモデルを変更できます。
$container[AIProviderInterface::class] = function ($c) {
    // 1. プロバイダーの選択
    // 環境変数が設定されていない場合は、デフォルトで 'gemini' を採用します。
    $provider = getenv('LLM_PROVIDER') ?: 'gemini';

    // ケースA: Google Gemini を使用する場合
    if ($provider === 'gemini') {
        // APIキーの取得と検証 (Fail Fast: キーがない場合は即座に例外を投げて停止)
        $apiKey = getenv('GEMINI_API_KEY') ?: throw new \Exception('Missing GEMINI_API_KEY 123');
        
        return new GeminiProvider(
            apiKey: $apiKey,
            // コンテナから共通のロガーを注入（通信ログ記録用）
            logger: $c[LoggerInterface::class],
            // 推奨モデル: 'gemini-1.5-flash'
            // 理由: 非常に高速かつ低コストで、ツール呼び出し（Function Calling）の精度も高いため、
            // リアルタイム性が求められるチャットボットやエージェントに最適です。
            model: 'gemini-1.5-flash', 
            // 温度 (Temperature): 0.3
            // 創造性を少し残しつつも、事実に基づいた回答を安定して出力させるための設定です。
            temperature: 0.3
        );
    }

    // ケースB: DeepSeek を使用する場合
    if ($provider === 'deepseek') {
        $apiKey = getenv('DEEPSEEK_API_KEY') ?: throw new \Exception('Missing DEEPSEEK_API_KEY');
        
        return new DeepSeekProvider(
            apiKey: $apiKey,
            logger: $c[LoggerInterface::class],
            model: 'deepseek-chat',
            // DeepSeek provider 側でデフォルト設定 (0.1等) があればそれが適用されますが、
            // 明示的に指定することも可能です。
            temperature: 0.1 
        );
    }

    // ケースC: Doubao (豆包) を使用する場合
    if ($provider === 'doubao') {
        $apiKey = getenv('DOBAO_API_KEY') ?: throw new \Exception('Missing DOBAO_API_KEY');

        // Volcengine Ark の OpenAI 互換エンドポイントに接続（/api/v3/chat/completions）
        // NeuronAI は OpenAI互換の Provider を想定しているため、OpenAILike を使う
        return new OpenAILike(
            baseUri: 'https://ark.cn-beijing.volces.com/api/v3',
            key: $apiKey,
            model: getenv('DOUBAO_CHAT_MODEL') ?: 'doubao-seed-2-0-mini-260215',
            parameters: [
                'temperature' => 0.5,
                'max_tokens' => 2048,
                'stream' => false,
            ],
            strict_response: false,
            httpClient: null
        );
    }

    // 不明なプロバイダーが指定された場合は例外を投げて明示的に失敗させる
    throw new \InvalidArgumentException("Unsupported LLM_PROVIDER: {$provider}");
};

// GetInstaller ワークフロー（Router の GetInstallerTool → Workflow → GetInstallerAgent → SearchInstallerTool）
$container[GetInstallerAgent::class] = function ($c) {
    return new GetInstallerAgent(
        $c[AIProviderInterface::class],
        $c[SearchInstallerTool::class]
    );
};

$container[ProcessInstallerQueryNode::class] = function ($c) {
    return new ProcessInstallerQueryNode($c[GetInstallerAgent::class]);
};

$container[GetInstallerWorkflow::class] = function ($c) {
    return new GetInstallerWorkflow($c[ProcessInstallerQueryNode::class]);
};

$container[GetInstallerTool::class] = function ($c) {
    return new GetInstallerTool($c[GetInstallerWorkflow::class]);
};

// ルーターエージェント本体
$container[RouterAgent::class] = function ($c) {
    return new RouterAgent(
        $c[AIProviderInterface::class],
        $c[LookupOrderTool::class],
        $c[CheckDeliveryTool::class],
        $c[SearchFaqTool::class],
        $c[GetInstallerTool::class]
    );
};

// AgentFactory
$container[AgentFactory::class] = function ($c) {
    // AgentFactory は PSR-11 の ContainerInterface を要求するため、
    // Pimple コンテナを Psr11Container でラップして渡す
    $psrContainer = new Psr11Container($c);
    return new AgentFactory($psrContainer);
};

// Redis接続（php-redis 拡張が必要。未導入時は /api/chat 利用時にエラーになります）
$container[\Redis::class] = function ($c) {
    if (!class_exists(\Redis::class, false)) {
        throw new \RuntimeException(
            'PHP Redis extension is required for chat history. Install php-redis and enable extension=redis in php.ini.'
        );
    }
    $redis = new \Redis();
    $redisHost = getenv('REDIS_HOST') ?: 'localhost';
    $redisPort = (int)(getenv('REDIS_PORT') ?: 6379);
    $redis->connect($redisHost, $redisPort);
    return $redis;
};

// ChatController
$container[ChatController::class] = function ($c) {
    return new ChatController(
        $c[AgentFactory::class],
        $c[\Redis::class],
        $c[GeneralChatAgent::class],
        $c[LoggerInterface::class]
    );
};

// RagController（RAG/step4 は Redis 未使用）
$container[RagController::class] = function ($c) {
    return new RagController(
        $c[AgentFactory::class],
        $c[KnowledgeBaseService::class],
        $c[ElasticsearchMemoryService::class]
    );
};

// --- コンテナ返却 ---
// Slim Frameworkなどが利用できるPSR-11形式に変換して返します。
$psr11Container = new Psr11Container($container);
return $psr11Container;