<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PDO;

/**
 * データベース保存用ハンドラー
 */
class DatabaseHandler extends AbstractProcessingHandler
{
    private PDO $pdo;
    private string $table;

    public function __construct(PDO $pdo, string $table, $level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->pdo = $pdo;
        $this->table = $table;
    }

    protected function write(LogRecord|array $record): void
    {
        // テーブル名の簡易バリデーション
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->table)) {
            throw new \InvalidArgumentException('Invalid table name');
        }

        try {
            // Monolog 3 (Object) と Monolog 2 (Array) の両対応
            $channel = $record['channel'] ?? 'app';
            $message = (string)($record['message'] ?? '');

            $level = $record['level'] ?? Logger::DEBUG;
            if ($level instanceof \Monolog\Level) {
                $level = $level->value;
            }
            $level = (int)$level;

            $levelName = $record['level_name'] ?? null;
            if ($levelName === null && isset($record['level']) && $record['level'] instanceof \Monolog\Level) {
                $levelName = $record['level']->getName();
            }
            if ($levelName === null && isset($record['level_name'])) {
                $levelName = (string)$record['level_name'];
            }
            $levelName = $levelName !== null ? (string)$levelName : (string)$level;

            $datetimeObj = $record['datetime'] ?? null;
            $createdAt = $datetimeObj instanceof \DateTimeInterface
                ? $datetimeObj->format('Y-m-d H:i:s')
                : (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $context = $record['context'] ?? [];
            $extra = $record['extra'] ?? [];

            $contextJson = $context !== null
                ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
                : null;
            $extraJson = $extra !== null
                ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
                : null;

            $remoteAddr = $context['remote_addr'] ?? $context['ip'] ?? null;
            $userAgent = $context['user_agent'] ?? $context['ua'] ?? null;
            $requestUri = $context['request_uri'] ?? $context['uri'] ?? null;

            $sql = "INSERT INTO `{$this->table}`
                (`channel`, `level`, `level_name`, `message`, `context`, `extra`, `remote_addr`, `user_agent`, `request_uri`, `created_at`)
                VALUES
                (:channel, :level, :level_name, :message, :context, :extra, :remote_addr, :user_agent, :request_uri, :created_at)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':channel' => $channel,
                ':level' => $level,
                ':level_name' => $levelName,
                ':message' => $message,
                ':context' => $contextJson,
                ':extra' => $extraJson,
                ':remote_addr' => $remoteAddr,
                ':user_agent' => $userAgent,
                ':request_uri' => $requestUri,
                ':created_at' => $createdAt,
            ]);
        } catch (\Throwable $e) {
            // ログ保存の失敗が原因でアプリを停止させないよう catch する
            error_log("DatabaseHandler Write Error: " . $e->getMessage());
        }
    }
}

