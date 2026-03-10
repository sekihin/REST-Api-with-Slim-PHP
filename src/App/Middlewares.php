<?php

declare(strict_types=1);

use Slim\App;
use Monolog\Logger;
use App\Infrastructure\Logging\DatabaseHandler;
use App\Infrastructure\Logging\DatabaseLogMiddleware;

return static function (App $app, $customErrorHandler = null): void {
    
    // 1. 基本設定
    $path = $_SERVER['SLIM_BASE_PATH'] ?? '';
    $app->setBasePath($path);
    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();

    // 2. ログ用DB接続 & ミドルウェア登録（PDO）
    // ※ Container.php 側の DatabaseHandler を PDO 前提にしたため、ここも PDO で統一
    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbUser = getenv('DB_USER') ?: 'root';
    $dbPass = getenv('DB_PASS') ?: '';
    $dbName = getenv('DB_NAME') ?: 'uisagent';
    $dbPort = (int)(getenv('DB_PORT') ?: 3306);

    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;port=%d;charset=utf8mb4', $dbHost, $dbName, $dbPort);
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $accessLogger = new Logger('access_log');
        $accessLogger->pushHandler(new DatabaseHandler($pdo, 'c_logs'));
        $app->add(new DatabaseLogMiddleware($accessLogger));
    } catch (\Throwable $e) {
        // 接続失敗時は標準エラーログに出力 (アプリは続行)
        error_log('Log DB Connection Failed: ' . $e->getMessage());
    }

    // 3. エラーハンドリング設定
    $displayError = filter_var($_SERVER['DISPLAY_ERROR_DETAILS'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $errorMiddleware = $app->addErrorMiddleware($displayError, true, true);
    
    if ($customErrorHandler) {
        $errorMiddleware->setDefaultErrorHandler($customErrorHandler);
    }
};