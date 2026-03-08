<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Memory;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Redis;

/**
 * Redisベースの会話履歴管理クラス
 * * 機能と役割:
 * 1. 永続化 (Persistence): チャット履歴をRedisのList構造に保存し、リクエストをまたいで会話を継続可能にします。
 * 2. 自動期限切れ (TTL): 長期間アクティブでないセッションを自動的に削除し、Redisのメモリを節約します。
 * 3. スライディングウィンドウ (Sliding Window): LLMのトークン制限（コンテキスト長）を超えないよう、
 * 過去の全履歴ではなく「直近のN件」のみを抽出して提供する仕組みを実装しています。
 */
class RedisMessageHistory
{
    /** @var Redis Redisクライアントインスタンス */
    private Redis $redis;
    
    /** @var string Redisキーとして使用するセッションID（プレフィックス付き） */
    private string $sessionId;
    
    /** @var int セッションの有効期限（秒） */
    private int $ttl;
    
    /** @var int コンテキストとして保持する最大メッセージ数（スライディングウィンドウのサイズ） */
    private int $windowSize;

    /**
     * コンストラクタ
     * * @param Redis  $redis      Redisクライアント接続済みインスタンス
     * @param string $sessionId  セッションの一意識別子 (例: 'user_123:session_abc')
     * @param int    $ttl        セッション有効期限 (秒)。デフォルトは1時間 (3600秒)
     * @param int    $windowSize コンテキストウィンドウサイズ (最大メッセージ数)。デフォルトは10件
     */
    public function __construct(
        Redis $redis, 
        string $sessionId, 
        int $ttl = 3600, 
        int $windowSize = 10
    ) {
        $this->redis = $redis;
        // キーの衝突（Key Conflict）を防ぐため、専用のプレフィックスを付与します
        $this->sessionId = "agent:history:{$sessionId}";
        $this->ttl = $ttl;
        $this->windowSize = $windowSize;
    }

    /**
     * ユーザーメッセージを追加
     * @param string $content メッセージ本文
     */
    public function addUserMessage(string $content): void
    {
        $this->addMessage('user', $content);
    }

    /**
     * AIアシスタントのメッセージを追加
     * @param string $content メッセージ本文
     */
    public function addAssistantMessage(string $content): void
    {
        $this->addMessage('assistant', $content);
    }

    /**
     * システムメッセージを追加
     * プロンプトに含めるのが一般的ですが、動的にルールを追加したい場合に使用します。
     * @param string $content メッセージ本文
     */
    public function addSystemMessage(string $content): void
    {
        $this->addMessage('system', $content);
    }

    /**
     * 会話履歴を取得する（スライディングウィンドウ適用）
     * * 全履歴ではなく、ウィンドウサイズで設定された「直近のN件」のみを返します。
     * これにより、LLMへのリクエストトークン量が爆発するのを防ぎます。
     * * @return array<Message> NeuronAI Messageオブジェクトの配列
     */
    public function getMessages(): array
    {
        // Redis LRANGE コマンド: リストの範囲取得
        // 0 は先頭、-1 は末尾を表します。
        // -windowSize から -1 を指定することで、「後ろからN件」を取得します。
        $rawMessages = $this->redis->lRange($this->sessionId, -$this->windowSize, -1);

        if (empty($rawMessages)) {
            return [];
        }

        $messages = [];
        foreach ($rawMessages as $json) {
            $data = json_decode($json, true);
            if ($data) {
                $role = (string) ($data['role'] ?? 'user');
                $content = $data['content'] ?? '';

                $messages[] = match ($role) {
                    MessageRole::USER->value => new UserMessage($content),
                    MessageRole::ASSISTANT->value => new AssistantMessage($content),
                    MessageRole::SYSTEM->value => new Message(MessageRole::SYSTEM, $content),
                    MessageRole::DEVELOPER->value => new Message(MessageRole::DEVELOPER, $content),
                    default => new Message(MessageRole::USER, $content),
                };
            }
        }

        return $messages;
    }

    /**
     * 現在のセッション履歴を完全に削除
     * ユーザーが「会話をリセット」した場合などに使用します。
     */
    public function clear(): void
    {
        $this->redis->del($this->sessionId);
    }

    /**
     * 内部メソッド: メッセージをRedisに書き込む
     * * @param string $role ロール (user, assistant, system)
     * @param string $content メッセージ内容
     */
    private function addMessage(string $role, string $content): void
    {
        $message = [
            'role' => $role,
            'content' => $content,
            'timestamp' => time()
        ];

        // RPUSH: リストの右端（末尾）に追加します。時系列順に保存されます。
        $this->redis->rPush($this->sessionId, json_encode($message));

        // 有効期限（TTL）をリフレッシュ
        // 会話が続く限り、セッションは維持されます。
        $this->redis->expire($this->sessionId, $this->ttl);

        // オプション: Redisのメモリ容量を保護するためのリストトリミング
        // ウィンドウサイズの2倍程度を残して、それより古いログを削除する処理を入れると安全です。
        // $this->redis->lTrim($this->sessionId, -($this->windowSize * 2), -1);
    }
}