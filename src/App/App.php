<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use App\App\ResponseFactory as CustomResponseFactory;

// オートローダー
require __DIR__ . '/../../vendor/autoload.php';

// 設定ファイル
require __DIR__ . '/DotEnv.php';
require_once __DIR__ . '/CustomResponse.php';
require_once __DIR__ . '/ResponseFactory.php';

// コンテナ取得 (ここで 'db' も定義済み)
$container = require __DIR__ . '/Container.php';

// AppFactory設定
AppFactory::setContainer($container);
$app = AppFactory::create();

// ミドルウェア・ルート設定
$customErrorHandler = require __DIR__ . '/ErrorHandler.php';
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

(require __DIR__ . '/Middlewares.php')($app, $customErrorHandler);
(require __DIR__ . '/Cors.php')($app);

// 【削除】 ↓この行を消す、またはコメントアウトする
// (require __DIR__ . '/Database.php');

(require __DIR__ . '/Services.php');
(require __DIR__ . '/Repositories.php');
(require __DIR__ . '/Routes.php')($app);
(require __DIR__ . '/NotFound.php')($app);

return $app;