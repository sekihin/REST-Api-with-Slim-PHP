<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    public function getAccessToken(Request $request, Response $response): Response
    {
        // TODO: 実際の認証ロジックに置き換える
        $data = [
            'status' => 'success',
            'code' => 200,
            'access_token' => 'your-generated-token-here',
            'expires_in' => 3600,
        ];

        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }
}

