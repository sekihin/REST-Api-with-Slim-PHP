<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Factories;

use App\Infrastructure\AI\Agents\OrderSupportAgent;
use Neuron\Providers\LLM\LLMInterface;
use App\Infrastructure\AI\Tools\LookupOrderTool;
use App\Infrastructure\AI\Tools\RefundOrderTool;
use App\Infrastructure\AI\Tools\CheckInventoryTool;
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
     * 注文サポートエージェントを作成する
     * @param string $userId 現在のユーザーID（コンテキストとして使用）
     * @return OrderSupportAgent 構成済みのエージェントインスタンス
     */
    public function createOrderSupportAgent(string $userId): OrderSupportAgent
    {
        // DIコンテナから必要な依存関係を取得
        $llm = $this->container->get(LLMInterface::class);
        $lookupOrderTool = $this->container->get(LookupOrderTool::class);
        $refundOrderTool = $this->container->get(RefundOrderTool::class);
        $checkInventoryTool = $this->container->get(CheckInventoryTool::class);

        // OrderSupportAgentをインスタンス化
        // OrderSupportAgentは内部でシステムプロンプトを構築します
        return new OrderSupportAgent(
            $llm,
            $lookupOrderTool,
            $refundOrderTool,
            $checkInventoryTool
        );
    }
}