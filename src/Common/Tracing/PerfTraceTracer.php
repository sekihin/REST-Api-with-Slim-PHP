<?php

declare(strict_types=1);

namespace App\Common\Tracing;

use App\Common\PerfTrace;

/**
 * PerfTrace の DI 用アダプター（既存の static API を TracerInterface 経由で注入可能にする）
 */
final class PerfTraceTracer implements TracerInterface
{
    public function now(): mixed
    {
        return PerfTrace::now();
    }

    public function log(string $name, mixed $start, array $context = []): void
    {
        if (!is_float($start)) {
            return;
        }

        PerfTrace::log($name, $start, $context);
    }
}
