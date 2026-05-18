<?php

declare(strict_types=1);

namespace App\Common;

/**
 * 本番向けの軽量パフォーマンス計測ログ（error_log 出力）
 */
final class PerfTrace
{
    public static function now(): float
    {
        return microtime(true);
    }

    /**
     * @param array<string, int|string|float|bool|null> $context
     */
    public static function log(string $step, float $start, array $context = []): void
    {
        $elapsed = microtime(true) - $start;
        $parts = [sprintf('[PERF] %s', $step), sprintf('time=%.4f sec', $elapsed)];

        foreach ($context as $key => $value) {
            if ($value === null) {
                continue;
            }
            $parts[] = sprintf('%s=%s', $key, (string) $value);
        }

        error_log(implode(' | ', $parts));
    }
}
