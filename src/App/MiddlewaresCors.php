<?php

declare(strict_types=1);

use Slim\App;
use Tuupola\Middleware\CorsMiddleware;
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

return static function (App $app, Closure $customErrorHandler): void {
    $path = $_SERVER['SLIM_BASE_PATH'] ?? '';
    $app->setBasePath($path);
    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();
	$app->add(new CorsMiddleware([
		"origin" => ["http://www.imeijin.com"],
		"methods" => ["GET", "POST", "PUT", "DELETE"],
		"headers.allow" => ["Authorization", "Content - Type"],
		"credentials" => false
	]));
    $displayError = filter_var(
        $_SERVER['DISPLAY_ERROR_DETAILS'] ?? false,
        FILTER_VALIDATE_BOOLEAN
    );
    $errorMiddleware = $app->addErrorMiddleware($displayError, true, true);
    $errorMiddleware->setDefaultErrorHandler($customErrorHandler);
};
