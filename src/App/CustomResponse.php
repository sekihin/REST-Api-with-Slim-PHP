<?php

declare(strict_types=1);

namespace App\App;

use Slim\Psr7\Response as ResponseBase;
use Slim\Psr7\Factory\StreamFactory;

final class CustomResponse extends ResponseBase
{
    public function withJson(
        $data,
        int $status = 200,
        int $encodingOptions = 0
    ): self {
        $json = json_encode($data, $encodingOptions);

        if ($json === false) {
            throw new \RuntimeException(
                json_last_error_msg(),
                json_last_error()
            );
        }

        $streamFactory = new StreamFactory();
        $stream = $streamFactory->createStream($json);
	
        // withBody は新しいインスタンス(clone)を返します
        // これにより PHP 7.2 時代と同様の「クリーンな状態」が保証されます
        $response = $this->withBody($stream)
            ->withHeader('Content-Type', 'application/json;charset=utf-8');

        if (isset($status)) {
            return $response->withStatus($status);
        }

        return $response;
    }
}
