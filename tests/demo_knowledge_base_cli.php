<?php

declare(strict_types=1);

use App\Domain\Knowledge\KnowledgeBaseService;

// オートローダー & 環境変数読み込み
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/App/DotEnv.php';

// DIコンテナ取得（Elasticsearch クライアントと EmbeddingProvider を注入した KnowledgeBaseService）
/** @var Psr\Container\ContainerInterface $container */
$container = require __DIR__ . '/../src/App/Container.php';

/** @var KnowledgeBaseService $kbService */
$kbService = $container->get(KnowledgeBaseService::class);

try {
    //echo "📦 ドキュメントの読み込みとインデックス化を開始します...\n";
    // スクリプト配置ディレクトリ（tests/）配下の `uis_md/` を探索
    //$kbService->processAllMarkdowns(__DIR__ . '/uis_md/');
    //echo "✅ 登録完了\n";

    echo "\n========================================\n";

    // テスト用の無名関数（クロージャ）を定義
    $testSearch = function (string $query, ?string $filterDocType = null) use ($kbService): void {
        $filterDisplay = $filterDocType ?? 'なし';
        echo "\n🔍 検索クエリ: '{$query}' | フィルタ: {$filterDisplay}\n";

        $results = $kbService->searchDocuments($query, $filterDocType, 3);

        if (empty($results)) {
            echo "  検索結果なし\n";
            return;
        }

        foreach ($results as $i => $hit) {
            $rank = $i + 1;
            echo "\n{$rank} [スコア: " . number_format((float) $hit['score'], 2) . "] [タイプ: {$hit['doc_type']}]\n";
            echo "ファイル名: {$hit['doc_id']}\n";
            echo "題名: {$hit['title']}\n";
            echo "内容: " . mb_substr($hit['content'], 0, 100) . "...\n";
        }
    };

    // テスト 1：通常検索
    $testSearch('パスワードを忘れた場合の対応を教えて');

    // テスト 2：メールテンプレートに絞り込み
    $testSearch('ライセンス期限が切れるユーザーに送るメール', 'email_template');
} catch (Exception $e) {
    echo "\n❌ エラー: " . $e->getMessage() . "\n";
    exit(1);
}
