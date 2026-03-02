<?php

declare(strict_types=1);

namespace App\Controller;

use App\App\CustomResponse as Response;
use Pimple\Psr11\Container;
use Psr\Http\Message\ServerRequestInterface as Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Bayfront\MonologPDO\PDOHandler;
use Monolog\Logger;

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
        $pdo = $this->container->get('db');
        $status = [
            'status' => [
                'database' => 'OK',
            ],
            'api' => self::API_NAME,
            'version' => self::API_VERSION,
            'timestamp' => time(),
        ];

        /** @var PDO $pdo */
        $log = (new Logger('channel_name'))->pushHandler(new PDOHandler($pdo, 'status_logs'));

		//Now you can use the logger, and further attach additional information
		$log->info('Adding a new user', array('username' => 'Seldaek'));

        return $response->withJson($status);
    }
}
