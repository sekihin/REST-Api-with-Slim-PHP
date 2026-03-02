<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

use Neuron\Tools\Tool;
use App\Domain\Knowledge\KnowledgeBaseService;
use Psr\Log\LoggerInterface;

class SearchManuaryTool extends Tool
{
    private KnowledgeBaseService $kbService;
    private LoggerInterface $logger;

    public function __construct(KnowledgeBaseService $kbService, LoggerInterface $logger)
    {
        $this->kbService = $kbService;
        $this->logger = $logger;
    }

    // AIにツールの目的を伝える定義
    protected string $name = 'search_manuals';
    protected string $description = 'マニュアルやメールテンプレートなどのナレッジベースを検索し、関連する文書を返します。ユーザーからの業務や規定に関する質問に答えるための文脈を取得する際に使用します。';

    // AIが渡すべき引数の定義 (JSON Schema)
    protected array $parameters = [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
                'description' => '検索したい内容のキーワードや自然言語（例: "パスワードリセット", "製品バージョンアップ操作手順"）'
            ]
        ],
        'required' => ['query']
    ];

    /**
     * ツールの実行ロジック
     */
    public function execute(array $args): string
    {
        $query = $args['query'] ?? '';

        if (empty(trim($query))) {
            return json_encode(['error' => '検索クエリを指定してください。']);
        }

        try {
            $this->logger->info("AI is searching manuals for: {$query}");

            // サービス層のベクトル検索を呼び出し (上位3件を取得)
            $results = $this->kbService->searchDocuments($query, 3);

            if (empty($results)) {
                return "関連するマニュアルや情報は見つかりませんでした。ユーザーに「該当する情報が見つからない」旨を伝えてください。";
            }

            // LLMが文脈として読みやすい形式（テキスト）にフォーマットして返す
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
}