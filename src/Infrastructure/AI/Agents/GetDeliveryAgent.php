<?php

declare(strict_types=1);

namespace App\Neuron\Agents;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use App\Infrastructure\AI\Tools\LookupOrderTool;
use App\Infrastructure\AI\Tools\CheckInventoryTool;

/**
 * 社内サポートオペレーター向け（契約・配送状況照会）AIエージェント。
 * ・社内スタッフからの要求に基づき、契約状況や配送状況を確認する。
 * ・買い切り契約以外の場合は「出荷不可」として判定し、構造化データを返却する。
 */
class GetDeliveryAgent extends Agent
{
    /**
     * @param AIProviderInterface $provider LLMプロバイダー (DeepSeek, Geminiなど)
     * @param LookupOrderTool $lookupTool 契約照会ツール
     * @param CheckDeliveryTool $deliveryTool 納期確認ツール
     */
    public function __construct(
        AIProviderInterface $provider,
        LookupOrderTool $lookupTool,
        CheckDeliveryTool $deliveryTool
    ) {
        parent::__construct($provider);

        // システムプロンプトの設定（非・買い切り契約への対応条件を追加）
        $this->setInstructions(
            "あなたは社内サポートオペレーターを支援する、優秀な調査アシスタントAIです。\n" .
            "社内スタッフからの「ソフトウェアの発送日」や「契約状況」に関する照会に対し、以下のステップで調査を行い、結果を構造化データで回答してください。\n\n" .
            "【ステップ 1: 契約照会】\n" .
            "必ず最初に `lookup_order` ツールを使用して、該当する契約の基本情報（契約の種類・ステータス）を確認してください。\n\n" .
            "【ステップ 2: 配送状況の確認と判定】\n" .
            "取得した契約が「買い切り（買取）」の契約である場合のみ、`check_delivery` ツールを使用して配送ステータスを取得してください。\n" .
            "※重要※ 該当の契約が「買い切り（買取）」ではない場合（サブスクリプション、ダウンロード版、クラウドサービスなど）、物理的な発送は発生しないため、ツールの呼び出しは不要です。直ちに「出荷不可（発送なし）」と判定してください。\n\n" .
            "【ステップ 3: 構造化データの出力】\n" .
            "顧客への直接の案内文章（挨拶など）は一切作成しないでください。\n" .
            "社内スタッフが一目で状況を把握し、顧客対応に利用できるよう、必ず以下のJSON形式で結果を出力してください。\n\n" .
            "```json\n" .
            "{\n" .
            "  \"order_id\": \"取得した契約ID\",\n" .
            "  \"current_status\": \"受付済み・未出荷 / 出荷済み / 出荷不可 など\",\n" .
            "  \"shipping_date_estimate\": \"発送予定日や実績日（買い切りではない場合は '発送なし' と設定）\",\n" .
            "  \"recommended_response\": \"顧客への回答方針（例: '買い切り商品ではないため、物理的な発送は発生しない旨をご案内してください'）\"\n" .
            "}\n" .
            "```\n\n" .
            "【重要】\n" .
            "出力は上記のJSONブロックのみとし、前後の説明文や「承知いたしました」などの返事は含めないでください。"
        );

        $this->addTool($lookupTool);
        $this->addTool($deliveryTool);
    }
}