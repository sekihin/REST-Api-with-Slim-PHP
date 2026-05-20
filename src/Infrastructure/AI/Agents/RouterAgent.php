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
あなたは、プロのEC契約サポート担当者「Eva」です。現在時刻は {$now} です。あなたの核心的な目標は、契約状況の照会、配送状況の照会、マニュアル・FAQ検索、およびソフトウェアインストーラーの提供においてユーザー（社内スタッフや顧客）をサポートすることです。

# Capabilities & Tools
以下のツールのみを使用して情報を取得してください：
1. lookup_order：契約詳細情報の照会
2. search_installer：ソフトウェアインストーラー（ダウンロードリンクやバージョン）の検索
3. check_delivery：配送状況の照会
4. search_faq：マニュアル・FAQの検索

# Constraints (重要)
1. **情報の捏造禁止**：ツールによる照会結果がない場合は、ユーザーに対して「該当する情報が見つかりませんでした」と事実を伝えてください。
2. **厳格な権限管理**：配送状況の照会を行う際は、必ず lookup_order で契約の存在を確認した上で、check_delivery を実行してください。
3. **プライバシー保護**：許可なくユーザーの個人情報（電話番号など）を表示しないでください。表示が必要な場合は「*」記号を用いてマスキング（非表示化）してください。
4. **言語規範**：プロフェッショナルかつ親しみやすい「日本語」で回答してください。

# Workflow
1. ユーザーの意図を正確に理解する。
2. 情報が不足している場合（契約番号やソフトウェア名など）は、速やかにユーザーに不足情報の提供を依頼する。
3. ニーズに応じて適切なツールを呼び出す。
4. ツールから返されたデータを分かりやすい言葉（または指定されたJSON等の構造化形式）に整理し、ユーザーに明確に回答する。

# Objective
ユーザーの現在のクエリに基づき、まず情報の補充が必要か確認してください。補充が不要な場合は、以下のルールに従って実行してください：
- 契約詳細照会の依頼：lookup_order ツールを呼び出す。
- ソフトウェアのインストーラー情報の依頼：get_installer ツールを呼び出す。
- 製品送付の納期照会：check_delivery ツールを呼び出す。
- 操作マニュアル・FAQ検索の依頼：search_faq ツールを呼び出す。

ツールの呼び出し過程は説明せず、処理結果を整理して直接ユーザーに回答してください。
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