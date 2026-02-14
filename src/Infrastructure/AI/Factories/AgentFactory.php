<?php

// src/Infrastructure/AI/Factories/AgentFactory.php

namespace App\Infrastructure\AI\Factories;

use Neuron\Agents\Agent;
use Neuron\Providers\LLM\OpenAI; // 仮定のLLMプロバイダー
use App\Infrastructure\AI\Tools\LookupOrderTool;
use App\Infrastructure\AI\Tools\RefundOrderTool;
use Psr\Container\ContainerInterface;

/**
 * AIエージェントファクトリ
 * * 特定の役割（注文サポートなど）を持つAIエージェントを生成し、
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
     * * @param ContainerInterface $container ツールクラスの依存解決のためにコンテナを注入
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * 注文サポートエージェントを作成する
     * * @param string $userId 現在のユーザーID（コンテキストとして使用）
     * @return Agent 構成済みのエージェントインスタンス
     */
    public function createOrderSupportAgent(string $userId): Agent
    {
        // 1. LLM（大規模言語モデル）の準備
        $llm = new OpenAI([
            'api_key' => getenv('OPENAI_API_KEY'),
            'model' => 'gpt-4-turbo',
            // 業務システムでは正確性が求められるため、Temperature（温度）を低く設定し、
            // 創造性よりも事実に基づいた決定論的な回答を優先させます。
            'temperature' => 0.2 
        ]);

        // 2. DIコンテナからツールインスタンスを取得
        // コンテナ経由で取得することで、各ツールに必要なService（例: OrderService）が自動注入されます。
        $tools = [
            $this->container->get(LookupOrderTool::class),
            $this->container->get(RefundOrderTool::class),
        ];

        // 3. 動的なシステムプロンプトの構築
        // エージェントの「人格」と「制約」を定義します。ユーザーIDなどの動的情報もここに埋め込みます。
        // プロンプトの内容（意訳）:
        // - あなたはプロの注文管理アシスタントです。
        // - 現在のユーザーID: {$userId}
        // - 提供されたツールのみを使用して回答すること。データの捏造は厳禁。
        // - 返金などの機密操作を行う場合は、必ず二次確認を行うこと。
        $systemPrompt = <<<PROMPT
あなたはプロの注文管理アシスタントです。
現在のユーザーID:  {$userId}。
提供されたツールのみを使用して回答すること。データの捏造は厳禁。
返金などの機密操作を行う場合は、必ず二次確認を行うこと。
PROMPT;

        // 4. エージェントの組み立てと返却
        return new Agent($llm, $tools, $systemPrompt);
    }
}