<?php

declare(strict_types=1);

namespace App\Neuron\Agents;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use App\Infrastructure\AI\Tools\LookupOrderTool;
use App\Infrastructure\AI\Tools\CheckInventoryTool;

/**
 * 主に カスタマーサポート（注文問い合わせ対応） を自動化するためのAIエージェント。
 * ・ユーザーからの自然言語の質問に基づき、注文状況や在庫状況を確認し、適切な返信を生成する。
 */
class GetDeliveryAgent extends Agent
{
    /**
     * @param AIProviderInterface $provider LLMプロバイダー (DeepSeek, Geminiなど)
     * @param LookupOrderTool $lookupTool 注文照会ツール
     * @param CheckInventoryTool $inventoryTool 在庫確認ツール
     */
    public function __construct(
        AIProviderInterface $provider,
        LookupOrderTool $lookupTool,
        CheckInventoryTool $inventoryTool
    ) {
        // 親クラス (Agent) のコンストラクタを呼び出し、LLMをセット
        parent::__construct($provider);

        // 1. システムプロンプト（AIへの詳細な指示とワークフロー定義）を設定
        // ここでデモシナリオのSTEP1〜3の思考ロジックをAIに教え込みます。
        $this->setInstructions(
            "あなたは優秀なECサイトのカスタマーサポートエージェントです。\n" .
            "ユーザーからの「商品が届かない」「発送されていない」といった配送状況に関する問い合わせに対して、以下の厳密なワークフローに従って対応してください：\n\n" .
            "【ステップ 1: 注文照会】\n" .
            "必ず最初に `lookup_order` ツールを使用して、ユーザーの注文状況を確認してください。ユーザーが注文番号を明記していない場合は、直近の注文を検索してください。\n\n" .
            "【ステップ 2: 在庫確認】\n" .
            "ステップ1で取得した注文ステータスが「処理中」や「未発送」の場合、遅延の根本原因を探るため、必ず `check_inventory` ツールを使用して該当商品の在庫状況を調べてください。\n\n" .
            "【ステップ 3: 判断と回答生成】\n" .
            "ツールの結果に基づき、以下の基準で回答を生成してください：\n" .
            "- 在庫が「0」の場合：発送遅延の原因が在庫切れであることを丁寧に説明し、深く謝罪してください。その上で、「商品の入荷をお待ちいただく」か、「注文のキャンセルと返金を希望される」かの選択肢をユーザーに提示してください。\n" .
            "- 在庫がある場合：現在出荷準備中である旨を伝え、もう少々お待ちいただくよう丁寧に案内してください。\n\n" .
            "【重要】\n" .
            "回答は常にプロフェッショナルで、ユーザーに寄り添った丁寧な日本語（敬語）を使用してください。AIとして振る舞うのではなく、人間のサポート担当者として回答してください。"
        );

        // 2. エージェントにツール（手足）を登録
        $this->addTool($lookupTool);
        $this->addTool($inventoryTool);
    }
}