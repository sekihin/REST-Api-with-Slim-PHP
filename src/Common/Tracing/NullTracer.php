<?php

declare(strict_types=1);

namespace App\Common\Tracing;

/**
 * 空の実装：トレースを一切行わない。テスト環境やトレース機能が未設定の場合に使用される
 */
final class NullTracer implements TracerInterface
{
    public function now(): mixed
    {
        return null;
    }

    public function log(string $name, mixed $start, array $context = []): void
    {
        // no-op
    }
}
