<?php

declare(strict_types=1);

namespace App\Controller;

use App\App\CustomResponse as Response;
use Pimple\Psr11\Container;
use Psr\Http\Message\ServerRequestInterface as Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;

final class HomeController
{
    private const API_NAME = 'slim4-api-skeleton';

    private const API_VERSION = '1.1.0';

    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function getHelp(Request $request, Response $response): Response
    {
        $jwtSecret = getenv('SECRET_KEY');
        if (!$jwtSecret) {
            return $response->withJson(['error' => 'JWT Secret Key not found'], 500);
        }

        $payload = [
            'iss' => 'your_issuer',
            'iat' => time(),
            'data' => [
                'api' => self::API_NAME,
                'version' => self::API_VERSION,
                'timestamp' => time()
            ]
        ];

        $jwtToken = JWT::encode($payload, $jwtSecret, 'HS256', 'JWT');

        $message = [
            'api' => self::API_NAME,
            'version' => self::API_VERSION,
            'timestamp' => time(),
            'jwt' => $jwtToken
        ];

        return $response->withJson($message);
    }

    public function getStatus(Request $request, Response $response): Response
    {
        $status = [
            'status' => [
                'database' => 'OK',
            ],
            'api' => self::API_NAME,
            'version' => self::API_VERSION,
            'timestamp' => time(),
        ];

        /** @var LoggerInterface $log */
        $log = $this->container->get('logger');
        $log->info('Status checked', [
            'database' => 'OK',
        ]);

        return $response->withJson($status);
    }
}
