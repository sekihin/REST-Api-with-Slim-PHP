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
use Throwable;

/**
 * マニュアル・FAQ検索ツール (SearchFaqTool)
 * AIエージェントがマニュアルやFAQを検索するためのツールです。
 */
class SearchFaqTool extends Tool
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
        
        // フィルタタイプを第2引数で受け取るように変更
        $this->setCallable(fn (string $query, ?string $filter_doc_type = null) => $this->run($query, $filter_doc_type));
    }

    private function buildProperties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: '検索したい内容のキーワードや自然言語（例: "パスワードリセット", "製品バージョンアップ操作手順"）',
                required: true
            ),
            new ToolProperty(
                name: 'filter_doc_type',
                type: PropertyType::STRING,
                description: '検索対象を特定のドキュメント種類に絞り込む場合に指定します（例: "email_template", "faq", "manual"）。指定がない場合は省略します。',
                required: false
            )
        ];
    }

    /**
     * ツールの実行ロジック
     */
    private function run(string $query, ?string $filterDocType = null): string
    {
        if (empty(trim($query))) {
            return json_encode(['error' => '検索クエリを指定してください。']);
        }

        try {
            $filterLog = $filterDocType ?? 'なし';
            $this->logger->info("AI is searching manuals for: {$query} | Filter: {$filterLog}");

            // ハイブリッド検索の呼び出し (Top K = 3)
            $results = $this->kbService->searchDocuments($query, $filterDocType, 3);

            if (empty($results)) {
                return "関連するマニュアルや情報は見つかりませんでした。ユーザーに「該当する情報が見つからない」旨を伝えてください。";
            }

            $formattedOutput = "以下の社内マニュアルが見つかりました：\n\n";
            foreach ($results as $idx => $doc) {
                $rank = $idx + 1;
                $title = $doc['title'];
                $docType = $doc['doc_type'];
                $content = $doc['content'];
                $formattedOutput .= "[参照資料 {$rank}: {$title} (Type: {$docType})]\n{$content}\n\n";
            }

            return $formattedOutput;
        } catch (Throwable $e) {
            $this->logger->error("SearchFaqTool Error: " . $e->getMessage());
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
