<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

/**
 * 豆包（火山引擎 Ark）API の request_id をヘッダーまたはレスポンス body から抽出する。
 */
final class DoubaoRequestId
{
    private const HEADER_CANDIDATES = [
        'x-request-id',
        'x-tt-logid',
        'x-req-id',
    ];

    /**
     * @param array<string, string|string[]> $headers Guzzle / PSR-7 形式
     */
    public static function fromHeaders(array $headers): string
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[strtolower((string) $name)] = is_array($values)
                ? (string) ($values[0] ?? '')
                : (string) $values;
        }

        foreach (self::HEADER_CANDIDATES as $header) {
            if (($normalized[$header] ?? '') !== '') {
                return $normalized[$header];
            }
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $body OpenAI 互換 JSON
     */
    public static function fromBody(array $body): ?string
    {
        if (isset($body['id']) && is_string($body['id']) && $body['id'] !== '') {
            return $body['id'];
        }

        return null;
    }

    /**
     * @param array<string, string|string[]> $headers
     * @param array<string, mixed>         $body
     */
    public static function resolve(array $headers, array $body = []): string
    {
        $fromHeaders = self::fromHeaders($headers);
        if ($fromHeaders !== 'unknown') {
            return $fromHeaders;
        }

        return self::fromBody($body) ?? 'unknown';
    }
}
