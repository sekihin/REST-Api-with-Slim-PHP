<?php

declare(strict_types=1);

use App\Domain\Knowledge\KnowledgeBaseService;

// オートローダー & 環境変数読み込み
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/App/DotEnv.php';

// DIコンテナ取得（Elasticsearch クライアントや EmbeddingProvider もここで準備される）
/** @var Psr\Container\ContainerInterface $container */
$container = require __DIR__ . '/src/App/Container.php';

/** @var KnowledgeBaseService $knowledgeBase */
$knowledgeBase = $container->get(KnowledgeBaseService::class);

// コマンドライン引数からクエリを取得
$query = $argv[1] ?? null;

if ($query === null) {
    fwrite(STDERR, "Usage: php knowledge_base_cli.php \"検索クエリ\"\n");
    exit(1);
}

// 検索実行
$results = $knowledgeBase->searchDocuments($query, 3);

if (empty($results)) {
    echo "ヒットなし\n";
    exit(0);
}

foreach ($results as $index => $doc) {
    $num = $index + 1;
    $title = $doc['title'] ?? '';
    $section = $doc['section'] ?? '';
    $docId = $doc['doc_id'] ?? '';
    $score = $doc['score'] ?? '';
    $content = $doc['content'] ?? '';

    echo "========== Result {$num} ==========\n";
    echo "Title   : {$title}\n";
    echo "Section : {$section}\n";
    echo "Doc ID  : {$docId}\n";
    echo "Score   : {$score}\n";
    echo "Content : " . mb_substr($content, 0, 200) . "...\n";
    echo "\n";
}

