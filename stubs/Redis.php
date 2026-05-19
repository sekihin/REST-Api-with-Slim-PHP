<?php

/**
 * IDE stub for the php-redis PECL extension (not autoloaded at runtime).
 *
 * @see https://github.com/phpredis/phpredis
 */
class Redis
{
    public function connect(
        string $host,
        int $port = 6379,
        float $timeout = 0,
        ?string $persistent_id = null,
        int $retry_interval = 0,
        float $read_timeout = 0,
        ?array $context = null
    ): bool {
        return true;
    }
}
