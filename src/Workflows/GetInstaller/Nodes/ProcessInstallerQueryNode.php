<?php

declare(strict_types=1);

namespace App\Workflows\GetInstaller\Nodes;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Chat\Messages\UserMessage;
use App\Infrastructure\AI\Agents\GetInstallerAgent;

/**
 * インストーラー照会を実行するノード
 */
class ProcessInstallerQueryNode extends Node
{
    private GetInstallerAgent $agent;

    public function __construct(GetInstallerAgent $agent)
    {
        $this->agent = $agent;
    }

    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        // 1. 状態（State）からユーザーの入力クエリを取得
        $userQuery = $state->get('query');

        if (!$userQuery) {
            $state->set('error', 'クエリが指定されていません。');
            return new StopEvent();
        }

        try {
            // 2. エージェントを実行し、結果（JSON文字列）を取得
            // ※ エージェント内で search_installer ツールが自動的に呼ばれます
            $response = $this->agent->chat([
                new UserMessage($userQuery)
            ])->getContent();

            // 3. 取得した構造化データを状態（State）に保存
            $state->set('installer_data', $response);

        } catch (\Throwable $e) {
            $state->set('error', 'エージェントの実行中にエラーが発生しました: ' . $e->getMessage());
        }

        // 4. 処理を終了するための StopEvent を返す
        return new StopEvent();
    }
}
