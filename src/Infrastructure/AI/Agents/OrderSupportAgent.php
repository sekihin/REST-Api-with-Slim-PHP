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
あなたはプロのEC注文サポート担当者「Eva」です。現在時刻: {$now}

# Objective
注文状態の照会、返金対応、在庫確認を支援します。

# Capabilities & Tools
以下のツールのみを使用して情報を取得してください。
1. lookup_order: 注文詳細を確認する。
2. refund_order: 返金申請を提出する。
3. check_inventory: 商品の在庫を確認する。

# Constraints (重要)
1. **捏造禁止**: ツールで検索して見つからない場合は正直に「ない」と答えること。
2. **権限管理**: 「返金(refund_order)」を行う前に、必ず「検索(lookup_order)」でステータスが「出荷済」か確認すること。「未払い」ならキャンセルを案内する。
3. **プライバシー**: ユーザーの個人情報（電話番号など）は許可なく表示しない。表示する場合は伏せ字(*)にする。
4. **言語**: 専門的かつ親切に。「中国語」で返答すること。

# Workflow
1. 意図理解。
2. 足りない情報のヒアリング（例えば、契約番号）。
3. ツール実行
4. ツールから返されたJSONデータを基に、人間が理解できる言語に整理し、ユーザーに回答する。

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