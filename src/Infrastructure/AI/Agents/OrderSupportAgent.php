<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Agents;

use Neuron\Agents\Agent;
use Neuron\Providers\LLM\LLMInterface;
use App\Infrastructure\AI\Tools\LookupOrderTool;
use App\Infrastructure\AI\Tools\RefundOrderTool;
use App\Infrastructure\AI\Tools\CheckInventoryTool;

/**
 * 注文サポート専任エージェント (Order Support Specialist Agent)
 * * * 責務: 注文の照会、アフターサービス（返金など）、在庫確認に関するユーザーからの問い合わせを一手に引き受けます。
 * * 特徴: 独自のシステムプロンプト（人格・ルール）を持ち、使用可能なツールが厳密に制限されています。
 */
class OrderSupportAgent extends Agent
{
    /**
     * @var array このエージェントが使用を許可されたツールのリスト
     * セキュリティ対策: カスタマーサポート用エージェントが、誤って「データベース削除」などの
     * 管理者用ツールを呼び出さないように、ホワイトリスト方式で制限します。
     */
    private array $allowedTools;

    /**
     * コンストラクタ
     * * * 必要なツール（検索、返金、在庫確認）を依存性注入（DI）で受け取ります。
     * * @param LLMInterface $llm 言語モデルプロバイダー
     * @param LookupOrderTool $lookupOrderTool 注文検索ツール
     * @param RefundOrderTool $refundOrderTool 返金処理ツール
     * @param CheckInventoryTool $inventoryTool 在庫確認ツール
     */
    public function __construct(
        LLMInterface $llm,
        LookupOrderTool $lookupOrderTool,
        RefundOrderTool $refundOrderTool,
        CheckInventoryTool $inventoryTool
    ) {
        // 1. ツールキットの組み立て
        // ここで定義されたツール以外は、AIがどれだけ要求しても使用できません。
        $this->allowedTools = [
            $lookupOrderTool,
            $refundOrderTool,
            $inventoryTool
        ];

        // 2. 親クラス（Agent）の初期化
        // システムプロンプトを動的に生成し（現在時刻の注入など）、LLMとツールをセットします。
        parent::__construct(
            $llm, 
            $this->allowedTools, 
            $this->buildSystemPrompt()
        );
    }

    /**
     * コアシステムプロンプトの構築
     * * * エージェントの「人格」「能力の境界」「出力形式」「業務ルール」を定義します。
     * * プロンプトエンジニアリングのベストプラクティスに基づき、AIの挙動を制御します。
     * * --- プロンプトの内容（日本語訳） ---
     * # Role: あなたはプロのEC注文サポート担当者「Eva」です。現在時刻: {$now}
     * # Objective: 注文状態の照会、返金対応、在庫確認を支援します。
     * # Capabilities: 以下のツールのみを使用して情報を取得してください (lookup_order, refund_order, check_inventory)。
     * # Constraints (重要):
     * 1. 捏造禁止: ツールで検索して見つからない場合は正直に「ない」と答えること。
     * 2. 権限管理: 「返金(refund_order)」を行う前に、必ず「検索(lookup_order)」でステータスが「出荷済」か確認すること。「未払い」ならキャンセルを案内する。
     * 3. プライバシー: ユーザーの個人情報（電話番号など）は許可なく表示しない。表示する場合は伏せ字(*)にする。
     * 4. 言語: 専門的かつ親切に。「中国語」で返答すること。
     * # Workflow: 意図理解 -> 足りない情報のヒアリング -> ツール実行 -> 結果の翻訳・回答
     * ----------------------------------------
     * * @return string 完成したシステムプロンプト
     */
    private function buildSystemPrompt(): string
    {
        $now = date('Y-m-d H:i:s');

        return <<<PROMPT
# Role
あなたは、プロのEC注文サポート担当者「Eva」です。現在時刻は <now>{{now}}</now> です。あなたの核心的な目標は、注文状況の照会、返金処理、および在庫確認においてユーザーをサポートすることです。

# Capabilities & Tools
以下のツールのみを使用して情報を取得してください：
1. lookup_order：注文詳細情報の照会
2. refund_order：返金申請の提出
3. check_inventory：商品在庫の確認

# Constraints (重要)
1. **情報の捏造禁止**：ツールによる照会結果がない場合は、ユーザーに対して「該当する情報が見つかりませんでした」と事実を伝えてください。
2. **厳格な権限管理**：返金処理（refund_order）を行う前に、必ず lookup_order で注文ステータスを確認してください。「未支払い」の場合は注文キャンセルの案内を行い、「発送済み」の場合のみ返金申請を受け付けます。
3. **プライバシー保護**：許可なくユーザーの個人情報（電話番号など）を表示しないでください。表示が必要な場合は「*」記号を用いてマスキング（非表示化）してください。
4. **言語規範**：プロフェッショナルかつ親しみやすい「日本語」で回答してください。

# Workflow
1. ユーザーの意図を正確に理解する。
2. 情報が不足している場合（注文番号など）は、速やかにユーザーに不足情報の提供を依頼する。
3. ニーズに応じて適切なツールを呼び出す。
4. ツールから返されたJSONデータを分かりやすい言葉に整理し、ユーザーに明確に回答する。

# Objective
ユーザーの現在のクエリ <user_query>{{user_query}}</user_query> に基づき、まず情報の補充が必要か確認してください。補充が不要な場合は、以下のルールに従って実行してください：
- 注文照会の依頼：lookup_order ツールを呼び出す。
- 返金の依頼：まず lookup_order で注文ステータスを確認し、その後に適切な操作を行う。
- 在庫照会の依頼：check_inventory ツールを呼び出す。

ツールの呼び出し過程は説明せず、処理結果を整理して直接ユーザーに回答してください。

PROMPT;
    }

    /**
     * オプション: ユーザーコンテキストの注入
     * * * 実行時に特定のユーザー情報をエージェントに認識させたい場合に使用します。
     * * セキュリティの向上や、よりパーソナライズされた対応が可能になります。
     * * @param string $userId ユーザーID
     * @param string $userName ユーザー名
     * @return self メソッドチェーン用
     */
    public function withUserContext(string $userId, string $userName): self
    {
        // ユーザー情報をシステムメッセージや会話履歴に追加
        $context = "現在のインタラクティブユーザー: {$userName} (ID: {$userId})"; // 現在の対話ユーザー: {$userName}
        
        // 親クラスに addSystemMessage メソッドがある前提の実装
        // 既存のプロンプトに追記することで、このリクエストの間だけ文脈を共有します。
        $this->addSystemMessage($context); 
        return $this;
    }
}