<?php

declare(strict_types=1);

use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$aliasPath = getenv('SLIM_ALIAS_PATH');
if (!$aliasPath) {
    $aliasPath = '/slim/';
}

$app->get($aliasPath, 'App\Controller\Home:getHelp');
$app->get($aliasPath . 'status', 'App\Controller\Home:getStatus')
    ->add(function (Request $request, RequestHandler $handler): Response {
        $authHeader = $request->getHeaderLine('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode(['error' => 'Token not provided or invalid']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $token = substr($authHeader, 7);
        $secretKey = $_SERVER['JWT_SECRET'] ?? 'your_jwt_secret_key';

        try {
            $decoded = Firebase\JWT\JWT::decode($token, new Firebase\JWT\Key($secretKey, 'HS256'));
            $request = $request->withAttribute('jwt', $decoded);
        } catch (Exception $e) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode(['error' => 'Invalid or expired token']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    });
