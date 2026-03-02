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
use App\Domain\Order\OrderRepository;
use App\Domain\Order\OrderService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Knowledge\KnowledgeBaseService;
use App\Infrastructure\Persistence\InMemoryOrderRepository;
// use App\Infrastructure\Persistence\MySQLOrderRepository; // 将来のDB切り替え用
use App\Infrastructure\AI\Agents\OrderSupportAgent;
use App\Infrastructure\AI\Factories\AgentFactory;
use App\Infrastructure\AI\Tools\LookupOrderTool;
use App\Infrastructure\AI\Tools\RefundOrderTool;
use App\Infrastructure\AI\Tools\CheckInventoryTool;
use App\Infrastructure\AI\Tools\SearchManuaryTool;
use App\Infrastructure\External\GeminiProvider;
use App\Infrastructure\External\DeepSeekProvider;
use App\Application\Controllers\ChatController;
use Neuron\Providers\LLM\LLMInterface;
use Psr\Log\LoggerInterface;
use Monolog\Logger;            // 追加: ロガーの実装クラス
use Monolog\Handler\StreamHandler; // 追加: 出力ハンドラー

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
        'mysql:host=%s;dbname=%s;port=%s;charset=utf8',
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

// 注文検索ツール
$container[LookupOrderTool::class] = function ($c) {
    return new LookupOrderTool($c[OrderService::class]);
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

// KnowledgeBaseService の登録
$container[KnowledgeBaseService::class] = function ($c) {
    $doubaoApiKey = getenv('DOUBAO_API_KEY') ?: throw new \Exception('Missing DOUBAO_API_KEY');
    return new KnowledgeBaseService(
        $c[\Elastic\Elasticsearch\Client::class],
        $doubaoApiKey
    );
};

// SearchManuaryTool の登録
$container[SearchManuaryTool::class] = function ($c) {
    return new SearchManuaryTool(
        $c[KnowledgeBaseService::class],
        $c[\Psr\Log\LoggerInterface::class]
    );
};

// --- 6. AIエージェント & LLM (Brain) ---

// LLMインターフェースの実装バインディング
// 環境変数 (LLM_PROVIDER) の値によって、インスタンス化するクラス（AIの脳）を動的に切り替えます。
// これにより、ベンダーロックインを防ぎ、コストや性能に応じて柔軟にモデルを変更できます。
$container[LLMInterface::class] = function ($c) {
    // 1. プロバイダーの選択
    // 環境変数が設定されていない場合は、デフォルトで 'gemini' を採用します。
    $provider = getenv('LLM_PROVIDER') ?: 'gemini';

    // ケースA: Google Gemini を使用する場合
    if ($provider === 'gemini') {
        // APIキーの取得と検証 (Fail Fast: キーがない場合は即座に例外を投げて停止)
        $apiKey = getenv('GEMINI_API_KEY') ?: throw new \Exception('Missing GEMINI_API_KEY');
        
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
    
    // ケースB: DeepSeek を使用する場合 (フォールバック)
    // 中国語の処理能力が高く、APIコストが安いため、代替案として優秀です。
    else {
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
};

// 注文サポートエージェント本体
// 必要なツールだけを持たせてインスタンス化します。
$container[OrderSupportAgent::class] = function ($c) {
    return new OrderSupportAgent(
        $c[LLMInterface::class],       // 脳
        $c[LookupOrderTool::class],
        $c[RefundOrderTool::class],
        $c[CheckInventoryTool::class],
        $c[SearchManuaryTool::class]
    );
};

// AgentFactory
$container[AgentFactory::class] = function ($c) {
    return new AgentFactory($c);
};

// Redis接続
$container[\Redis::class] = function ($c) {
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
        $c[\Redis::class]
    );
};

// --- コンテナ返却 ---
// Slim Frameworkなどが利用できるPSR-11形式に変換して返します。
$psr11Container = new Psr11Container($container);
return $psr11Container;