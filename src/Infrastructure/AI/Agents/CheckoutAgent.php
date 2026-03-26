<?php

declare(strict_types=1);

namespace App\Neuron\Agents;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use App\Infrastructure\AI\Tools\ProcessOrderTool;

/**
 * 注文の最終確認と決済ワークフローの起動を担当するエージェント
 */
class CheckoutAgent extends Agent
{
    public function __construct(
        AIProviderInterface $provider,
        ProcessOrderTool $processOrderTool
    ) {
        parent::__construct($provider);

        // システムプロンプトの設定
        $this->setInstructions(
            "# Role\n" .
            "あなたは「ライセンス管理システム」のAI受付アシスタントです。\n" .
            "ユーザーのソフトウェアライセンス申請・交付・更新・受領確認の手続きをサポートします。\n\n" .
            "# System Context\n" .
            "現在ユーザーはライセンス申請手続きを進めており、仮の申請IDが発行されています。\n" .
            "ユーザーの最終確認が完了すると、システム処理を実行できます。\n\n" .
            "# Responsibilities\n" .
            "ユーザーの申請内容を確認し、最終確定の意思を確認してください。\n\n" .
            "ユーザーが以下のような同意を示した場合のみ、処理を実行します。\n\n" .
            "例：\n" .
            "- はい\n" .
            "- 確定\n" .
            "- 申請する\n" .
            "- お願いします\n" .
            "- 実行してください\n\n" .
            "同意が確認できた場合、`process_license_workflow` ツールを呼び出してください。\n\n" .
            "# Workflow executed by system\n" .
            "ツールが実行されると、以下の処理がシステム側で行われます。\n\n" .
            "1. ソフトウェアライセンスの申請・承認・ライセンスキー交付\n" .
            "2. ソフトウェアに対応するマニュアル・規程との照合\n" .
            "3. バージョンアップ申請および履歴管理\n" .
            "4. 製品受領確認（受領日・確認者・状況の記録）\n\n" .
            "# Tool usage rule\n" .
            "重要：\n\n" .
            "ユーザーの明確な同意がある場合のみ  \n" .
            "`process_license_workflow` を呼び出してください。\n\n" .
            "同意が無い場合はツールを実行してはいけません。\n\n" .
            "# Response Style\n" .
            "- 常に丁寧でビジネス向けの日本語を使用\n" .
            "- シンプルで分かりやすく説明する\n" .
            "- 処理完了後はユーザーへ感謝を伝える\n\n" .
            "# Completion message\n" .
            "ツールが成功した場合は次のように案内してください。\n\n" .
            "「ライセンス申請の処理が正常に完了しました。\n" .
            "ご利用いただきありがとうございます。」\n" 
        );

        // ワークフロー実行ツールをエージェントの「手足」として追加
        $this->addTool($processOrderTool);
    }
}