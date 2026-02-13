<?php

declare(strict_types=1);

use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Slim\Psr7\Response as SlimResponse;

// 環境変数からパスを取得 (設定がない場合はルート直下とする修正を推奨)
$aliasPath = getenv('SLIM_ALIAS_PATH');
if (!$aliasPath) {
    // '/api-test/' だと URLが /agent/api-test/getAccessToken になってしまうため、
    // シンプルに '/' に変更するか、空文字にするのが一般的です。
    // ここでは既存のロジックを尊重しつつ、空文字(ルート)にしています。
    $aliasPath = '/'; 
}

// --- 修正点: POST だけでなく GET も許可する ---
$app->map(['GET', 'POST'], '/test', function (Request $request, Response $response) {
    
    $data = ['name' => 'test', 'value' => 123];

    // ログ確認用
    error_log('Debug Data: ' . print_r($data, true));
    error_log('Passed check point A');

    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

// 通常のルート定義
$app->map(['GET', 'POST'], $aliasPath . 'getAccessToken', 'App\Controller\uisAgent:getAccessToken');

// ミドルウェア付きのルート定義
$app->map(['GET', 'POST'], $aliasPath . 'aichat', 'App\Controller\uisAgent:getAnswer')
    ->add(function (Request $request, RequestHandler $handler): Response {
        // (中略: 既存のJWTロジックそのままでOK)
        $authHeader = $request->getHeaderLine('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Token not provided or invalid']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $token = substr($authHeader, 7);
        $secretKey = $_SERVER['JWT_SECRET'] ?? 'your_jwt_secret_key';

        try {
            $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
            
            if (isset($decoded->iat)) {
                $iat_timestamp = $decoded->iat;
                $invalid_cutoff = strtotime('2025-06-26 00:00:00');
                if ($iat_timestamp < $invalid_cutoff) {
                    throw new \Exception('expired token (iat check)');
                }
            }
            $request = $request->withAttribute('jwt', $decoded);

        } catch (\Exception $e) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode([
                'error' => 'Invalid or expired token',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    });