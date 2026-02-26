<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class DatabaseLogMiddleware
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function __invoke(Request $request, RequestHandlerInterface $handler): Response
    {
        // 1. リクエスト情報の記録
        $this->logger->info('Request received', [
            'method' => $request->getMethod(),
            'uri'    => (string)$request->getUri(),
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua'     => $request->getHeaderLine('User-Agent'),
        ]);

        // 2. 次の処理を実行
        $response = $handler->handle($request);

        // 3. レスポンス情報の記録
        $this->logger->info('Response generated', [
            'status' => $response->getStatusCode(),
            'content_type' => $response->getHeaderLine('Content-Type')
        ]);

        return $response;
    }
}

