<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Factories;

use App\Infrastructure\AI\Agents\RouterAgent;
use NeuronAI\Providers\AIProviderInterface;
use App\Infrastructure\AI\Tools\LookupOrderTool;
use App\Infrastructure\AI\Tools\CheckDeliveryTool;
use App\Infrastructure\AI\Tools\SearchFaqTool;
use App\Infrastructure\AI\Tools\GetInstallerTool;
use App\Common\Tracing\TracerInterface;
use Psr\Container\ContainerInterface;

/**
 * AIエージェントファクトリ
 * 特定の役割（注文サポートなど）を持つAIエージェントを生成し、
 * 必要なツールやプロンプト設定を行って返却するクラスです。
 */
class AgentFactory
{
    /**
     * @var ContainerInterface DIコンテナ
     */
    private ContainerInterface $container;

    /**
     * コンストラクタ
     * @param ContainerInterface $container ツールクラスの依存解決のためにコンテナを注入
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * ルーターエージェントを作成する
     * @param string $userId 現在のユーザーID（コンテキストとして使用）
     * @return RouterAgent 構成済みのエージェントインスタンス
     */
    public function createRouterAgent(string $userId): RouterAgent
    {
        // DIコンテナから必要な依存関係を取得
        $llm = $this->container->get(AIProviderInterface::class);
        $lookupOrderTool = $this->container->get(LookupOrderTool::class);
        $checkDeliveryTool = $this->container->get(CheckDeliveryTool::class);
        $searchFaqTool = $this->container->get(SearchFaqTool::class);
        $GetInstallerTool = $this->container->get(GetInstallerTool::class);

        return new RouterAgent(
            $llm,
            $lookupOrderTool,
            $checkDeliveryTool,
            $searchFaqTool,
            $GetInstallerTool,
            tracer: $this->container->get(TracerInterface::class),
        );
    }
}