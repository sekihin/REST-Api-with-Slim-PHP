namespace App\Neuron\Agents\History;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\Message;
use Redis;

class RedisChatHistory extends AbstractChatHistory
{
    private Redis $redis;
    private string $key;

    /**
     * @param Redis  $redis         Redisクライアントのインスタンス
     * @param string $sessionId     セッションやユーザーを識別するユニークキー
     * @param int    $contextWindow トークン数の上限（デフォルト50000）
     */
    public function __construct(Redis $redis, string $sessionId, int $contextWindow = 50000)
    {
        $this->redis = $redis;
        $this->key = "neuron_chat_history:{$sessionId}";
        
        // 親クラスのコンストラクタを呼び出してコンテキストウィンドウを設定
        parent::__construct($contextWindow);
        
        // インスタンス化時にRedisから既存の履歴を読み込む
        $this->loadMessages();
    }

    /**
     * Redisから履歴を読み込み、親クラスの $this->history にセットする独自の初期化メソッド
     */
    private function loadMessages(): void
    {
        $data = $this->redis->get($this->key);
        
        if ($data) {
            // オブジェクトの配列として復元
            $messages = unserialize($data);
            $this->history = is_array($messages) ? $messages : [];
        }
    }

    /**
     * 1. 履歴全体を保存する (一括上書き)
     */
    protected function setMessages(array $messages): void
    {
        // Redisにシリアライズして保存（必要に応じて有効期限 expire を設定してもOK）
        $this->redis->set($this->key, serialize($messages));
    }

    /**
     * 2. 新しいメッセージが1件追加されたときの処理
     */
    protected function onNewMessage(Message $message): void
    {
        // AbstractChatHistory 側で $this->history への追加は済んでいるため、
        // 最新の状態をそのままRedisに保存し直すだけで機能します。
        $this->setMessages($this->history);
    }

    /**
     * 3. トークン上限により古いメッセージが切り詰められたときの処理
     */
    protected function onTrimHistory(int $index): void
    {
        // 0番目から $index 番目までの古いメッセージが削除された際に呼ばれます。
        // こちらも $this->history は既に更新されているため、全体を上書き保存します。
        $this->setMessages($this->history);
    }

    /**
     * 4. 履歴をすべて削除する処理
     */
    protected function clear(): void
    {
        $this->redis->del($this->key);
    }
}