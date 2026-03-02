<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Monolog\Logger;
use Pimple\Psr11\Container;

class AuthController
{
    private const API_NAME = 'slim4-api-skeleton';

    private const API_VERSION = '1.1.0';

    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function getAccessToken(Request $request, Response $response): Response
    {
        $jwtSecret = getenv('JWT_SECRET');
        if (!$jwtSecret) {
            $payload = json_encode(['error' => 'JWT Secret Key not found'], 500);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json');
        }

        $payload = [
            'iss' => 'your_issuer',
            'iat' => time(),
            //'exp' => time() + 3600,    // 1時間有効
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
            'AccessToken' => $jwtToken
        ];

        $payload = json_encode($message, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }
}

