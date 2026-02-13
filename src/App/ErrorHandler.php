<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface;

return function (
    ServerRequestInterface $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app): Response {
    $statusCode = 500;

    // Ensure the exception code is within the range of valid HTTP status codes
    if (is_int($exception->getCode()) &&
        $exception->getCode() >= 400 &&
        $exception->getCode() <= 500
    ) {
        $statusCode = $exception->getCode();
    }

    // Get the class name of the exception without using `::class`
    $className = (new \ReflectionClass(get_class($exception)))->getShortName();

    $data = [
        'message' => $exception->getMessage(),
        'class' => $className,
        'status' => 'error',
        'code' => $statusCode,
    ];

    $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    $response = $app->getResponseFactory()->createResponse();
    $response->getBody()->write($body);

    return $response
        ->withStatus($statusCode)
        ->withHeader('Content-Type', 'application/problem+json');
};

