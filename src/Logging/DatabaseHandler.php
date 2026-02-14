<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use mysqli;

/**
 * データベース保存用ハンドラー
 */
class DatabaseHandler extends AbstractProcessingHandler
{
    private mysqli $mysqli;
    private string $table;

    public function __construct(mysqli $mysqli, string $table, $level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->mysqli = $mysqli;
        $this->table = $table;
    }

    protected function write(LogRecord|array $record): void
    {
        // テーブル名の簡易バリデーション
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->table)) {
            throw new \InvalidArgumentException('Invalid table name');
        }

        $sql = "INSERT INTO `{$this->table}` 
                (channel, level, message, context, created_at)
                VALUES (?, ?, ?, ?, ?)";

        $stmt = $this->mysqli->prepare($sql);
        if ($stmt === false) {
            // ログ書き込み失敗はアプリを落とさず、エラーログに残すのが一般的
            error_log("DatabaseHandler Prepare Failed: {$this->mysqli->error}");
            return;
        }

        try {
            // Monolog 3 (Object) と Monolog 2 (Array) の両対応
            $levelVal = $record['level'];
            if ($levelVal instanceof \Monolog\Level) {
                $levelVal = $levelVal->value;
            }

            // datetimeの取得
            $datetimeObj = $record['datetime'];
            $datetimeStr = $datetimeObj->format('Y-m-d H:i:s');

            // コンテキストのJSON化
            $contextStr = json_encode($record['context'], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            $channel = $record['channel'];
            $message = $record['message'];

            // バインド (s=string, i=int)
            $bindResult = $stmt->bind_param(
                'sisss',
                $channel,
                $levelVal,
                $message,
                $contextStr,
                $datetimeStr
            );

            if (!$bindResult) {
                throw new \RuntimeException("Bind failed: {$stmt->error}");
            }

            $stmt->execute();

        } catch (\Throwable $e) {
            // ログ保存の失敗が原因でアプリを停止させないよう catch する
            error_log("DatabaseHandler Write Error: " . $e->getMessage());
        } finally {
            $stmt->close();
        }
    }
}