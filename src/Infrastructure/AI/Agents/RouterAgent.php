<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Agents;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use App\Infrastructure\AI\Tools\LookupOrderTool;
use App\Infrastructure\AI\Tools\CheckDeliveryTool;
use App\Infrastructure\AI\Tools\SearchFaqTool;
use App\Common\Tracing\TracerInterface;
use App\Common\Tracing\NullTracer;
use App\Infrastructure\AI\Tools\GetInstallerTool;
use App\Neuron\Agents\History\RedisChatHistory;
use Redis;

/**
 * ルーターエージェント (Router Agent)
 * 責務: 契約の照会、配送状況の照会、マニュアル・FAQ検索に関するユーザーからの問い合わせを一手に引き受けます。
 * 特徴: 独自のシステムプロンプト（人格・ルール）を持ち、使用可能なツールが厳密に制限されています。
 */
class RouterAgent extends Agent
{
    /**
     * @var array このエージェントが使用を許可されたツールのリスト
     * セキュリティ対策: カスタマーサポート用エージェントが、誤って「データベース削除」などの
     * 管理者用ツールを呼び出さないように、ホワイトリスト方式で制限します。
     */
    private array $allowedTools;

    /** @var callable|null */
    private $tokenCallback = null;

    private TracerInterface $tracer;

    /**
     * ストリーミング時にトークンが届くたびに呼ばれるコールバックを登録
     */
    public function onToken(callable $cb): void
    {
        $this->tokenCallback = $cb;
    }

    /**
     * コンストラクタ
     * 必要なツール（契約検索、配送照会、マニュアル・FAQ検索）を依存性注入（DI）で受け取ります。
     * @param AIProviderInterface $llm 言語モデルプロバイダー
     * @param LookupOrderTool $lookupOrderTool 契約検索ツール
     * @param CheckDeliveryTool $checkDeliveryTool 配送状況照会ツール
     * @param SearchFaqTool $searchFaqTool マニュアル・FAQ検索ツール
     * @param GetInstallerTool $GetInstallerTool インストーラー検索ツール
     */
    public function __construct(
        AIProviderInterface $llm,
        LookupOrderTool $lookupOrderTool,
        CheckDeliveryTool $checkDeliveryTool,
        SearchFaqTool $searchFaqTool,
        GetInstallerTool $GetInstallerTool,
        ?TracerInterface $tracer      = null
    ) {
        // NeuronAI\Agent\Agent は Workflow を継承しており、親コンストラクタで workflowId 等が初期化される。
        // これを呼ばないと Typed property Workflow::$workflowId の未初期化エラーになる。
        parent::__construct();

        $this->allowedTools = [
            $lookupOrderTool,
            $checkDeliveryTool,
            $searchFaqTool,
            $GetInstallerTool // 追加
        ];

        $this->setAiProvider($llm);
        $this->addTool($this->allowedTools);
        $this->basePrompt = $this->buildSystemPrompt();
        $this->setInstructions($this->basePrompt);
        $this->tracer = $tracer ?? new NullTracer();
    }

    /**
     * 文字列入力で実行し、Message を返す（短期記憶対応版）
     *
     * 注意:
     * - NeuronAI の基底クラス (Workflow) には run(): Generator が定義されているため、
     *   ここで run(...) を宣言すると PHP の互換性チェックで Fatal error になります。
     * @param string $userMessage ユーザーからの入力
     * @param string|null $sessionId セッションID (指定がない場合は履歴を使わない)
     */
    public function reply(string $userMessage, ?string $sessionId = null): Message
    {
        // Redis 拡張が無い環境では履歴機能を無効化して単発チャットにフォールバックする
        if ($sessionId && !class_exists(\Redis::class, false)) {
            $sessionId = null;
        }

        // セッションIDがない場合は、単発のメッセージとして処理
        if (!$sessionId) {
            $tChat = $this->tracer->now();
            $handler = $this->tokenCallback !== null
                ? $this->stream([new UserMessage($userMessage)])
                : $this->chat([new UserMessage($userMessage)]);
            $message = $this->resolveHandlerMessage($handler);
            $this->tracer->log('Agent.Chat', $tChat, [
                'session_id' => 'none',
                'message_length' => strlen($userMessage),
            ]);
            return $message;
        }

        // 1. Redisから過去のメッセージ配列を取得
        $tHistoryLoad = $this->tracer->now();
        $history = $this->chatHistoryForSession($sessionId);
        $messages = $history->getMessages();
        $this->tracer->log('Agent.RedisHistoryLoad', $tHistoryLoad, [
            'session_id' => $sessionId,
            'history_count' => count($messages),
        ]);

        // 2. 今回の新しいユーザーメッセージを配列の末尾に追加
        $messages[] = new UserMessage($userMessage);

        // 3. エージェントに過去の文脈ごと渡して回答を生成
        $tChat = $this->tracer->now();
        $handler = $this->tokenCallback !== null
            ? $this->stream($messages)
            : $this->chat($messages);
        $response = $this->resolveHandlerMessage($handler);
        $this->tracer->log('Agent.Chat', $tChat, [
            'session_id' => $sessionId,
            'message_length' => strlen($userMessage),
            'history_count' => count($messages),
            'reply_length' => strlen($response->getContent()),
        ]);

        // 4. 今回のやり取りをRedisに保存 (次回以降の文脈のため)
        $tHistorySave = $this->tracer->now();
        $history->addUserMessage($userMessage);
        $history->addAssistantMessage($response->getContent());
        $this->tracer->log('Agent.RedisHistorySave', $tHistorySave, ['session_id' => $sessionId]);

        return $response;
    }

    private function resolveHandlerMessage(AgentHandler $handler): Message
    {
        if ($this->tokenCallback !== null) {
            foreach ($handler->events() as $event) {
                if ($event instanceof TextChunk) {
                    ($this->tokenCallback)($event->content);
                }
            }
        }

        return $handler->getMessage();
    }

    /**
     * コアシステムプロンプトの構築
     * エージェントの「人格」「能力の境界」「出力形式」「業務ルール」を定義します。
     * プロンプトエンジニアリングのベストプラクティスに基づき、AIの挙動を制御します。
     * @return string 完成したシステムプロンプト
     */
    private function buildSystemPrompt(): string
    {
        $now = date('Y-m-d H:i:s');

        return <<<PROMPT
# Role
あなたはEC契約サポート担当「Eva」です。現在時刻:  {$now}

# Tools
1. lookup_order — 契約詳細の照会（契約番号が必要）
2. get_installer — インストーラー情報の取得
3. check_delivery — 配送状況の照会（※必ずlookup_orderで契約確認後に実行）
4. search_faq — マニュアル・FAQ検索

# Constraints (重要)
- ツールの結果がない場合は「該当情報が見つかりませんでした」と伝える
- 個人情報（電話番号等）は「*」でマスキングする
- ツールの呼び出し過程は説明せず、結果のみを回答する
- 言語：日本語（丁寧かつ親しみやすいトーン）

# Workflow & Objective
ユーザーのリクエストを受けたら：
1. 必要な情報（契約番号など）が揃っているか確認する。不足があれば先に質問する
2. 以下に従いツールを呼び出す：
   - 契約照会 → lookup_order
   - インストーラー → get_installer
   - 配送照会 → lookup_orderで確認後 → check_delivery
   - FAQ/マニュアル → search_faq
3. 結果を整理して回答する
PROMPT;
    }

    /**
     * オプション: ユーザーコンテキストの注入
     * 実行時に特定のユーザー情報をエージェントに認識させたい場合に使用します。
     * セキュリティの向上や、よりパーソナライズされた対応が可能になります。
     * @param string $userId ユーザーID
     * @param string $userName ユーザー名
     * @return self メソッドチェーン用
     */
    public function withUserContext(string $userId, string $userName): self
    {
        $context = "現在のインタラクティブユーザー: {$userName} (ID: {$userId})";
        $this->setInstructions($this->resolveInstructions() . "\n\n" . $context);
        return $this;
    }

    /**
     * RAG 用ナレッジコンテキストの注入
     * 取得済みのマニュアル・FAQなどのテキストを、追加のシステム指示として付与します。
     * @param string $knowledgeContext ナレッジベースから取得したテキスト
     * @return self メソッドチェーン用
     */
    public function withHybridContext(string $memoryContext, string $knowledgeContext): self
    {
        if (trim($knowledgeContext) === '') {
            return $this;
        }

        $hybridPrompt = $this->basePrompt . "\n\n# Contexts\n以下のコンテキストを使用して質問に回答してください。\n\n";

        if (trim($memoryContext) !== '') {
            $hybridPrompt .= "## User memories (この特定のユーザーに関してあなたが知っていること):\n{$memoryContext}\n\n";
        } else {
            $hybridPrompt .= "## User memories:\n特になし\n\n";
        }

        if (trim($knowledgeContext) !== '') {
            $hybridPrompt .= "## Knowledge base (リファレンスドキュメント・RAG):\n{$knowledgeContext}\n\n";
        }

        $this->setInstructions($this->resolveInstructions() . "\n\n" . $hybridPrompt);

        return $this;
    }

    /**
     * セッションIDベースの履歴取得
     *
     * 注意:
     * - NeuronAI\Agent\Agent には chatHistory(): ChatHistoryInterface が存在するため、
     *   同名メソッドでシグネチャを変えると PHP の互換性チェックで Fatal error になります。
     */
    public function chatHistoryForSession(string $sessionId): RedisChatHistory
    {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);

        return new RedisChatHistory($redis, $sessionId);
    }
}