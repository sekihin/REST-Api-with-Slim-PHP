<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Agents;

use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use App\Infrastructure\AI\Tools\SearchInstallerTool;

/**
 * インストーラー照会専用アシスタント
 */
class GetInstallerAgent extends Agent
{
    public function __construct(
        AIProviderInterface $provider,
        SearchInstallerTool $searchInstallerTool
    ) {
        $this->setAiProvider($provider);

        $this->setInstructions(
            "あなたは社内サポートオペレーターを支援する、ソフトウェアインストーラーの調査アシスタントです。\n" .
            "社内スタッフからの「〇〇のインストーラー」や「ダウンロードリンク」に関する照会に対し、以下のステップで対応してください。\n\n" .
            "【ステップ 1: インストーラーの検索】\n" .
            "必ず `search_installer` ツールを使用して、該当ソフトウェアのダウンロード情報を検索してください。\n\n" .
            "【ステップ 2: 構造化データの出力】\n" .
            "顧客への直接の挨拶などは一切不要です。\n" .
            "社内スタッフがそのまま顧客へ案内できるよう、必ず以下のJSON形式で結果を出力してください。\n\n" .
            "```json\n" .
            "{\n" .
            "  \"software_name\": \"検索されたソフトウェアの正式名称\",\n" .
            "  \"version\": \"バージョン情報（例: v2.1.0）\",\n" .
            "  \"os_type\": \"Windows / macOS / Linux など\",\n" .
            "  \"download_link\": \"https://... (取得できない場合は '提供なし' と記載)\",\n" .
            "  \"release_date\": \"YYYY-MM-DD (不明な場合は '不明')\",\n" .
            "  \"operator_note\": \"顧客へ案内する際の注意事項（例: 'インストール前に旧バージョンのアンインストールが必要です'）\"\n" .
            "}\n" .
            "```\n\n" .
            "【重要】\n" .
            "出力は上記のJSONブロックのみとしてください。"
        );

        $this->addTool($searchInstallerTool);
    }
}