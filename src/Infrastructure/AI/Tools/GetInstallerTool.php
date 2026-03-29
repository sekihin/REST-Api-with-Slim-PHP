<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use App\Workflows\GetInstaller\GetInstallerWorkflow;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolCallableNotSet;

/**
 * インストーラー照会ワークフロー実行ツール
 * RouterAgent から呼び出され、内部で GetInstallerWorkflow を実行します。
 */
class GetInstallerTool extends Tool
{
    private GetInstallerWorkflow $workflow;

    protected string $name = 'get_installer';
    protected ?string $description = '製品名とOSに基づいてソフトウェアのインストーラー（ダウンロードリンクやバージョン）に関する情報を取得するための専門ワークフローを実行します。';
    protected array $properties = [];

    public function __construct(GetInstallerWorkflow $workflow)
    {
        parent::__construct(
            name: $this->name,
            description: $this->description,
            properties: $this->buildProperties(),
            annotations: []
        );

        $this->workflow = $workflow;
        $this->setCallable(fn (string $query) => $this->run($query));
    }

    private function buildProperties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'ユーザーが求めているインストーラーに関する具体的な質問内容（例: "製品AのWindows版の最新インストーラーを教えて"）',
                required: true
            )
        ];
    }

    private function run(string $query): string
    {
        try {
            // ワークフローを初期化して実行
            $finalState = $this->workflow->init(['query' => $query])->run();

            // ワークフローのステートから結果を取り出してエージェントに返す
            if ($finalState->get('error')) {
                return json_encode([
                    'status' => 'error',
                    'message' => $finalState->get('error')
                ], JSON_UNESCAPED_UNICODE);
            }

            // ワークフローが生成した構造化データ（JSON文字列）をそのままエージェントに渡す
            return $finalState->get('installer_data') ?? 'データが見つかりませんでした。';

        } catch (\Throwable $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'ワークフロー実行中にシステムエラーが発生しました: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function execute(): void
    {
        try {
            parent::execute();
        } catch (MissingCallbackParameter | ToolCallableNotSet $e) {
            $this->setResult(json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}