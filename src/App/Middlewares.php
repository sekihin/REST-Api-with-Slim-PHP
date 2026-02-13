<?php

declare(strict_types=1);

use Slim\App;

return static function (App $app, $customErrorHandler): void {
    
    // --- ApacheのAliasに合わせてベースパスを設定 ---
    $app->setBasePath('/agent');

    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();

    // ログミドルウェアの読み込み
    $logMiddlewareConfig = require __DIR__ . '/DatabaseLogMiddleware.php';
    if (is_callable($logMiddlewareConfig)) {
        $logMiddlewareConfig($app);
    }

    $displayError = filter_var(
        $_SERVER['DISPLAY_ERROR_DETAILS'] ?? false,
        FILTER_VALIDATE_BOOLEAN
    );

    $errorMiddleware = $app->addErrorMiddleware($displayError, true, true);
    
    if ($customErrorHandler) {
        $errorMiddleware->setDefaultErrorHandler($customErrorHandler);
    }
};