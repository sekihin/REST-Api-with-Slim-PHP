<?php

declare(strict_types=1);

use Pimple\Container;
use Pimple\Psr11\Container as Psr11Container;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use App\App\ResponseFactory as CustomResponseFactory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Bayfront\MonologPDO\PDOHandler; // 必要であればuse追加

// Pimpleコンテナの初期化
$container = new Container();

// --- 依存関係の定義 ---

// 1. Nyholm Psr17Factory (PSR-7 Factory)
$container[Psr17Factory::class] = function ($c) {
    return new Psr17Factory();
};

// 2. CustomResponseFactory
$container[CustomResponseFactory::class] = function ($c) {
    return new CustomResponseFactory();
};

// 3. ServerRequestCreator
$container[ServerRequestCreator::class] = function ($c) {
    $psr17Factory = $c[Psr17Factory::class];
    return new ServerRequestCreator(
        $psr17Factory, // ServerRequestFactory
        $psr17Factory, // UriFactory
        $psr17Factory, // UploadedFileFactory
        $psr17Factory  // StreamFactory
    );
};

// 4. 'request'
$container['request'] = function ($c) {
    return $c[ServerRequestCreator::class]->fromGlobals();
};

// 5. Interfaceバインディング
$container[ResponseFactoryInterface::class] = function ($c) {
    return $c[CustomResponseFactory::class];
};

$container[StreamFactoryInterface::class] = function ($c) {
    return $c[Psr17Factory::class];
};

// 6. データベース接続 (Database.php の内容を移植)
$container['db'] = function ($c) {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;port=%s;charset=utf8',
        getenv('DB_HOST'),
        getenv('DB_NAME'),
        getenv('DB_PORT')
    );
    
    $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // 必要であればテーブル作成等の初期化処理 (Database.phpにあったロジック)
    // ※毎回走らせるのが重い場合は外してください
    if (class_exists(PDOHandler::class)) {
        $handler = new PDOHandler($pdo, 'status_logs');
        $handler->up();
    }

    return $pdo;
};

// --- コンテナをラップして返す ---
$psr11Container = new Psr11Container($container);

return $psr11Container;