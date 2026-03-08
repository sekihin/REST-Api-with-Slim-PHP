<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Agents;

use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use App\Infrastructure\AI\Tools\LookupOrderTool;
use App\Infrastructure\AI\Tools\CheckDeliveryTool;
use App\Infrastructure\AI\Tools\SearchManuaryTool;

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

    /**
     * コンストラクタ
     * 必要なツール（契約検索、配送照会、マニュアル・FAQ検索）を依存性注入（DI）で受け取ります。
     * @param AIProviderInterface $llm 言語モデルプロバイダー
     * @param LookupOrderTool $lookupOrderTool 契約検索ツール
     * @param CheckDeliveryTool $checkDeliveryTool 配送状況照会ツール
     * @param SearchManuaryTool $searchFaqTool マニュアル・FAQ検索ツール
     */
    public function __construct(
        AIProviderInterface $llm,
        LookupOrderTool $lookupOrderTool,
        CheckDeliveryTool $checkDeliveryTool,
        SearchManuaryTool $searchFaqTool
    ) {
        $this->allowedTools = [
            $lookupOrderTool,
            $checkDeliveryTool,
            $searchFaqTool
        ];

        // NeuronAI の Agent は setAiProvider / addTool / setInstructions で構成する
        $this->setAiProvider($llm);
        $this->addTool($this->allowedTools);
        $this->setInstructions($this->buildSystemPrompt());
    }

    /**
     * 既存コード互換: 文字列入力で実行し、Message を返す
     */
    public function run(string $userMessage): Message
    {
        return $this->chat([new UserMessage($userMessage)]);
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
あなたは、プロのEC契約サポート担当者「Eva」です。現在時刻は {$now} です。あなたの核心的な目標は、契約状況の照会、配送状況の照会、およびマニュアル・FAQ検索においてユーザーをサポートすることです。

# Capabilities & Tools
以下のツールのみを使用して情報を取得してください：
1. lookup_order：契約詳細情報の照会
2. check_delivery：配送状況の照会
3. search_faq：マニュアル・FAQの検索

# Constraints (重要)
1. **情報の捏造禁止**：ツールによる照会結果がない場合は、ユーザーに対して「該当する情報が見つかりませんでした」と事実を伝えてください。
2. **厳格な権限管理**：配送状況の照会を行う際は、必ず lookup_order で契約の存在を確認した上で、check_delivery を実行してください。
3. **プライバシー保護**：許可なくユーザーの個人情報（電話番号など）を表示しないでください。表示が必要な場合は「*」記号を用いてマスキング（非表示化）してください。
4. **言語規範**：プロフェッショナルかつ親しみやすい「日本語」で回答してください。

# Workflow
1. ユーザーの意図を正確に理解する。
2. 情報が不足している場合（契約番号など）は、速やかにユーザーに不足情報の提供を依頼する。
3. ニーズに応じて適切なツールを呼び出す。
4. ツールから返されたJSONデータを分かりやすい言葉に整理し、ユーザーに明確に回答する。

# Objective
ユーザーの現在のクエリに基づき、まず情報の補充が必要か確認してください。補充が不要な場合は、以下のルールに従って実行してください：
- 契約照会の依頼：lookup_order ツールを呼び出す。
- 配送状況の照会：check_delivery ツールを呼び出す。
- マニュアル・FAQ検索の依頼：search_faq ツールを呼び出す。

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

        // 既存のシステム指示に追記することで、このリクエストの間だけ文脈を共有します。
        $this->setInstructions($this->resolveInstructions() . "\n\n" . $context);
        return $this;
    }

    /**
     * RAG 用ナレッジコンテキストの注入
     * 取得済みのマニュアル・FAQなどのテキストを、追加のシステム指示として付与します。
     * @param string $knowledgeContext ナレッジベースから取得したテキスト
     * @return self メソッドチェーン用
     */
    public function withKnowledgeContext(string $knowledgeContext): self
    {
        if (trim($knowledgeContext) === '') {
            return $this;
        }

        $context = "# Knowledge Context\n以下は社内ナレッジベースから取得した参考情報です。内容を優先的に参照しつつ、ユーザーの質問に回答してください。\n\n{$knowledgeContext}";
        $this->setInstructions($this->resolveInstructions() . "\n\n" . $context);

        return $this;
    }
}
