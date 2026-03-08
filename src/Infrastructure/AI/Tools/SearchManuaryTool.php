<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use App\Domain\Knowledge\KnowledgeBaseService;
use Psr\Log\LoggerInterface;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolCallableNotSet;

/**
 * マニュアル・FAQ検索ツール (SearchManuaryTool)
 * AIエージェントがマニュアルやFAQを検索するためのツールです。
 */
class SearchManuaryTool extends Tool
{
    private KnowledgeBaseService $kbService;
    private LoggerInterface $logger;

    protected string $name = 'search_faq';
    protected ?string $description = 'マニュアル・FAQを検索し、関連する文書を返します。業務や規定に関する質問に答える際に使用します。';
    protected array $properties = [];

    public function __construct(KnowledgeBaseService $kbService, LoggerInterface $logger)
    {
        parent::__construct(
            name: $this->name,
            description: $this->description,
            properties: $this->buildProperties(),
            annotations: []
        );

        $this->kbService = $kbService;
        $this->logger = $logger;
        $this->setCallable(fn (string $query) => $this->run($query));
    }

    private function buildProperties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: '検索したい内容のキーワードや自然言語（例: "パスワードリセット", "製品バージョンアップ操作手順"）',
                required: true
            )
        ];
    }

    /**
     * ツールの実行ロジック
     */
    private function run(string $query): string
    {
        if (empty(trim($query))) {
            return json_encode(['error' => '検索クエリを指定してください。']);
        }

        try {
            $this->logger->info("AI is searching manuals for: {$query}");

            $results = $this->kbService->searchDocuments($query, 3);

            if (empty($results)) {
                return "関連するマニュアルや情報は見つかりませんでした。ユーザーに「該当する情報が見つからない」旨を伝えてください。";
            }

            $formattedOutput = "以下の社内マニュアルが見つかりました：\n\n";
            foreach ($results as $idx => $doc) {
                $rank = $idx + 1;
                $title = $doc['title'];
                $section = $doc['section'];
                $content = $doc['content'];
                $formattedOutput .= "[参照資料 {$rank}: {$title} ({$section})]\n{$content}\n\n";
            }

            return $formattedOutput;
        } catch (\Throwable $e) {
            $this->logger->error("SearchManuaryTool Error: " . $e->getMessage());
            return "ナレッジベースの検索中にシステムエラーが発生しました。別の方法で案内してください。";
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
