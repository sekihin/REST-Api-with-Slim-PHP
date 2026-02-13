<?php

declare(strict_types=1);

use Slim\App;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\RequestHandlerInterface;
use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

// ---------------------------------------------------------
// 1. クラス定義 (関数の外に出す)
// ---------------------------------------------------------

/**
 * データベース保存用ハンドラー (Monolog v3/v2対応)
 */
class DatabaseHandler extends AbstractProcessingHandler
{
    private $mysqli;
    private $table;

    public function __construct(mysqli $mysqli, string $table, $level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->mysqli = $mysqli;
        $this->table = $table;
    }

    /**
     * ログ書き込み処理
     * Monolog 3 は LogRecord オブジェクト、Monolog 2 は array が渡される
     */
    protected function write(LogRecord|array $record): void
    {
        // テーブル名のバリデーション
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->table)) {
            throw new \InvalidArgumentException('Invalid table name');
        }

        // SQL準備 (channel, level, message, context, created_at)
        $sql = "INSERT INTO `{$this->table}` 
                (channel, level, message, context, created_at)
                VALUES (?, ?, ?, ?, ?)";

        $stmt = $this->mysqli->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException("Prepare failed ({$this->mysqli->errno}): {$this->mysqli->error}");
        }

        try {
            // --- データの正規化 (Monolog 3/2 互換処理) ---
            
            // Level (Int)
            $levelVal = $record['level'];
            if ($levelVal instanceof \Monolog\Level) { // Monolog 3 Enum対応
                $levelVal = $levelVal->value;
            }

            // Datetime (String)
            $datetimeObj = $record['datetime'];
            $datetimeStr = $datetimeObj->format('Y-m-d H:i:s');

            // Context (JSON String)
            $contextStr = json_encode($record['context'], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            $channel = $record['channel'];
            $message = $record['message'];

            // --- バインド実行 ---
            // s=string, i=integer
            // channel(s), level(i), message(s), context(s), created_at(s)
            $bindResult = $stmt->bind_param(
                'sisss',
                $channel,
                $levelVal,
                $message,
                $contextStr,
                $datetimeStr
            );

            if (!$bindResult) {
                throw new \RuntimeException("Bind failed: ({$stmt->errno}) {$stmt->error}");
            }

            // 実行
            if (!$stmt->execute()) {
                throw new \RuntimeException("Execute failed: ({$stmt->errno}) {$stmt->error}");
            }

        } finally {
            $stmt->close();
        }
    }
}

/**
 * ミドルウェアクラス
 */
class DatabaseLogMiddleware
{
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function __invoke(Request $request, RequestHandlerInterface $handler): Response
    {
        // リクエストログ
        $this->logger->info('Request received', [
            'method' => $request->getMethod(),
            'uri' => (string)$request->getUri(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $response = $handler->handle($request);
        
        // レスポンスログ
        $this->logger->info('Response generated', [
            'status' => $response->getStatusCode(),
            'content_type' => $response->getHeaderLine('Content-Type')
        ]);

        return $response;
    }
}

// ---------------------------------------------------------
// 2. 設定と登録を行うクロージャを返す
// ---------------------------------------------------------

return static function (App $app): void {

    // DB接続設定
    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbUser = getenv('DB_USER') ?: 'root';
    $dbPass = getenv('DB_PASS') ?: '';
    $dbName = getenv('DB_NAME') ?: 'uisagent'; // デフォルト値を設定
    $dbPort = (int)(getenv('DB_PORT') ?: 3306);

    // ログ専用のコネクション
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);

    if ($mysqli->connect_error) {
        // DB接続失敗時は標準エラーログに出して続行 (アプリ自体は落とさない)
        error_log('Log DB Connection Failed: ' . $mysqli->connect_error);
        return;
    }

    // 文字コード設定
    $mysqli->set_charset('utf8mb4');

    // Loggerインスタンス作成
    $logger = new Logger('app');
    
    // ハンドラー登録 ('s_logs' テーブルを使用)
    $logger->pushHandler(new DatabaseHandler($mysqli, 's_logs'));

    // ミドルウェア登録
    $app->add(new DatabaseLogMiddleware($logger));
};