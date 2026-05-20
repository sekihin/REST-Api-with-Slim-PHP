<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

/**
 * Guzzle ミドルウェアが直近レスポンスのヘッダーを書き込む共有ホルダー。
 */
final class LastResponseHeaders
{
    /** @var array<string, string|string[]> */
    private array $headers = [];

    /** @param array<string, string|string[]> $headers */
    public function set(array $headers): void
    {
        $this->headers = $headers;
    }

    /** @return array<string, string|string[]> */
    public function get(): array
    {
        return $this->headers;
    }
}
