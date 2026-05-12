<?php

declare(strict_types=1);

namespace App\Workflows\GetInstaller;

use NeuronAI\Workflow\Workflow;
use App\Workflows\GetInstaller\Nodes\ProcessInstallerQueryNode;

/**
 * ソフトウェアインストーラー照会ワークフロー
 */
class GetInstallerWorkflow extends Workflow
{
    /**
     * @var ProcessInstallerQueryNode
     */
    private ProcessInstallerQueryNode $queryNode;

    // 依存性注入（DI）でノードを受け取る設計にしておくとテストが容易です
    public function __construct(ProcessInstallerQueryNode $queryNode)
    {
        parent::__construct();
        $this->queryNode = $queryNode;
    }

    protected function nodes(): array
    {
        return [
            $this->queryNode,
        ];
    }
}
