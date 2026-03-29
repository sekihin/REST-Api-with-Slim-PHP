<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use App\Domain\Software\SoftwareService;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolCallableNotSet;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * GetInstallerAgent 専用: ドメインの SoftwareService を直接呼び出すツール。
 * Router 向けの GetInstallerTool（ワークフロー）とは別にし、循環依存を防ぎます。
 */
class SearchInstallerTool extends Tool
{
    private SoftwareService $softwareService;

    protected string $name = 'search_installer';
    protected ?string $description = 'ソフトウェア名・OS・バージョンに基づきインストーラー情報（ダウンロードURL等）を検索します。';
    protected array $properties = [];

    public function __construct(SoftwareService $softwareService)
    {
        parent::__construct(
            name: $this->name,
            description: $this->description,
            properties: $this->buildProperties(),
            annotations: []
        );

        $this->softwareService = $softwareService;
        $this->setCallable(
            fn (string $software_name, string $os_type, string $version = '') => $this->run($software_name, $os_type, $version)
        );
    }

    private function buildProperties(): array
    {
        return [
            new ToolProperty(
                name: 'software_name',
                type: PropertyType::STRING,
                description: 'ソフトウェアの名称（例: 製品A）',
                required: true
            ),
            new ToolProperty(
                name: 'os_type',
                type: PropertyType::STRING,
                description: 'OS（例: Windows / macOS / Linux）',
                required: true
            ),
            new ToolProperty(
                name: 'version',
                type: PropertyType::STRING,
                description: 'バージョン（未指定の場合は空文字または「最新」）',
                required: false
            ),
        ];
    }

    private function run(string $softwareName, string $osType, string $version): string
    {
        try {
            $ver = $version !== '' ? $version : 'latest';
            $info = $this->softwareService->findInstaller($softwareName, $osType, $ver);

            if ($info === null) {
                return json_encode([
                    'status' => 'not_found',
                    'message' => '該当するインストーラー情報が見つかりませんでした。',
                ], JSON_UNESCAPED_UNICODE);
            }

            return json_encode([
                'status' => 'success',
                'software_name' => $info->getName(),
                'version' => $info->getVersion(),
                'os_type' => $info->getOs(),
                'download_link' => $info->getUrl(),
                'release_date' => $info->getReleaseDate(),
                'operator_note' => $info->getNotes(),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode([
                'status' => 'error',
                'message' => 'インストーラー検索中にエラーが発生しました: ' . $e->getMessage(),
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
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}
