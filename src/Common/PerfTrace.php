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
     * request_payload ログ用コンテキスト（has_request_payload で出力可否を制御）
     *
     * @return array{has_request_payload: 'yes'|'no'}|array{has_request_payload: 'yes', request_payload: string}
     */
    public static function requestPayloadFields(string $encodedPayload): array
    {
        if (!self::wantsRequestPayload()) {
            return ['has_request_payload' => 'no'];
        }

        return [
            'has_request_payload' => 'yes',
            'request_payload'     => $encodedPayload,
        ];
    }

    /** 環境変数 PERF_LOG_REQUEST_PAYLOAD（yes/1/true/on）で payload 詳細ログを有効化 */
    public static function wantsRequestPayload(): bool
    {
        $value = getenv('PERF_LOG_REQUEST_PAYLOAD');
        if ($value === false || $value === '') {
            return false;
        }

        return in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true);
    }

    /**
     * @param array<string, int|string|float|bool|null> $context
     */
    public static function log(string $step, float $start, array $context = []): void
    {
        $elapsed = microtime(true) - $start;
        $parts = [sprintf('[PERF] %s', $step), sprintf('time=%.4f sec', $elapsed)];

        $payload            = null;
        $hasRequestPayload  = 'no';

        if (array_key_exists('has_request_payload', $context)) {
            $hasRequestPayload = ($context['has_request_payload'] === 'yes') ? 'yes' : 'no';
        }

        if (array_key_exists('request_payload', $context)) {
            $payload = (string) $context['request_payload'];
            if (!array_key_exists('has_request_payload', $context)) {
                $hasRequestPayload = ($payload !== '' && self::wantsRequestPayload()) ? 'yes' : 'no';
            }
        }

        foreach ($context as $key => $value) {
            if ($value === null) {
                continue;
            }
            if ($key === 'request_payload' || $key === 'has_request_payload') {
                continue;
            }
            $parts[] = sprintf('%s=%s', $key, (string) $value);
        }

        $parts[] = sprintf('has_request_payload=%s', $hasRequestPayload);

        error_log(implode(' | ', $parts));

        if ($hasRequestPayload === 'yes' && $payload !== null && $payload !== '') {
            error_log(sprintf('[PERF] %s | request_payload=%s', $step, $payload));
        }
    }
}
