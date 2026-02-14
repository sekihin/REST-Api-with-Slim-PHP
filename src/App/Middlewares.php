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

    // 2. ログ用DB接続 & ミドルウェア登録
    // 環境変数から設定を取得
    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbUser = getenv('DB_USER') ?: 'root';
    $dbPass = getenv('DB_PASS') ?: '';
    $dbName = getenv('DB_NAME') ?: 'uisagent';
    $dbPort = (int)(getenv('DB_PORT') ?: 3306);

    // ログ専用のコネクション (アプリ本体のPDOとは別)
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);

    if (!$mysqli->connect_error) {
        $mysqli->set_charset('utf8mb4');

        // ロガーの作成
        $accessLogger = new Logger('access_log');
        
        // ハンドラーを登録 (s_logs テーブル)
        $accessLogger->pushHandler(new DatabaseHandler($mysqli, 's_logs'));

        // ミドルウェアとしてアプリに追加
        $app->add(new DatabaseLogMiddleware($accessLogger));
    } else {
        // 接続失敗時は標準エラーログに出力 (アプリは続行)
        error_log('Log DB Connection Failed: ' . $mysqli->connect_error);
    }

    // 3. エラーハンドリング設定
    $displayError = filter_var($_SERVER['DISPLAY_ERROR_DETAILS'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $errorMiddleware = $app->addErrorMiddleware($displayError, true, true);
    
    if ($customErrorHandler) {
        $errorMiddleware->setDefaultErrorHandler($customErrorHandler);
    }
};