<?php

declare(strict_types=1);

namespace App\Domain\Knowledge;

use GuzzleHttp\Promise\PromiseInterface as GuzzlePromiseInterface;
use GuzzleHttp\Promise\Promise as GuzzlePromise;
use Elastic\Elasticsearch\Client as ElasticsearchClient;
use App\Domain\Knowledge\EmbeddingProviderInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class KnowledgeBaseService
{
    private ElasticsearchClient $esClient;
    private EmbeddingProviderInterface $embeddingProvider;

    // インデックス名を変更
    private const INDEX_NAME = "rag_heterogeneous_docs";

    public function __construct(ElasticsearchClient $esClient, EmbeddingProviderInterface $embeddingProvider)
    {
        $this->esClient = $esClient;
        $this->embeddingProvider = $embeddingProvider;
    }

    /**
     * ハイブリッド検索を実行し、整形済みの配列を返す
     * (ネイティブKNN + キーワードマッチ)
     */
    public function searchDocuments(string $query, ?string $filterDocType = null, int $topK = 3): array
    {
        // 1. クエリのベクトル化
        $queryVector = $this->getEmbedding($query);

        // cosine 類似度は零ベクトルを受け付けない（埋め込み API 失敗時などに全要素 0 が返る）
        $useKnn = !$this->isZeroMagnitudeVector($queryVector);

        // 2. ハイブリッド検索のクエリ構築（KNN 不可時はキーワード検索のみ）
        $boolQuery = [
            'should' => [
                ['match' => ['content' => ['query' => $query, 'boost' => 1.0]]],
                ['match' => ['metadata.similar_queries' => ['query' => $query, 'boost' => 1.5]]],
            ],
        ];
        if ($filterDocType) {
            $boolQuery['filter'] = [['term' => ['metadata.doc_type' => $filterDocType]]];
        }

        $body = [
            'size' => $topK,
            '_source' => ['content', 'metadata'],
            'query' => ['bool' => $boolQuery],
        ];

        if ($useKnn) {
            $body['knn'] = [
                'field' => 'embedding',
                'query_vector' => $queryVector,
                'k' => $topK,
                'num_candidates' => 50,
            ];
            if ($filterDocType) {
                $body['knn']['filter'] = ['term' => ['metadata.doc_type' => $filterDocType]];
            }
        }

        $params = [
            'index' => self::INDEX_NAME,
            'body'  => $body
        ];

        // 4. 検索実行
        $response = $this->esClient->search($params);
        $res = $response->asArray();

        $totalHits = is_array($res["hits"]["total"]) ? $res["hits"]["total"]["value"] : $res["hits"]["total"];
        if ($totalHits == 0) {
            return [];
        }

        // 5. 結果のフォーマット
        $formattedResults = [];
        foreach ($res["hits"]["hits"] as $hit) {
            $source = $hit['_source'];
            $meta = $source['metadata'] ?? [];

            $formattedResults[] = [
                'title'    => $meta['title'] ?? '不明',
                'doc_type' => $meta['doc_type'] ?? '不明',
                'doc_id'   => $meta['doc_id'] ?? '不明',
                'content'  => $source['content'] ?? '',
                'score'    => $hit['_score']
            ];
        }

        return $formattedResults;
    }

    /**
     * 非同期(Promise)でハイブリッド検索を実行する
     */
    public function searchDocumentsAsync(string $query, ?string $filterDocType = null, int $topK = 3): GuzzlePromiseInterface
    {
        // 1. クエリのベクトル化
        $queryVector = $this->getEmbedding($query);

        // cosine 類似度は零ベクトルを受け付けない（埋め込み API 失敗時などに全要素 0 が返る）
        $useKnn = !$this->isZeroMagnitudeVector($queryVector);

        // 2. ハイブリッド検索のクエリ構築（KNN 不可時はキーワード検索のみ）
        $boolQuery = [
            'should' => [
                ['match' => ['content' => ['query' => $query, 'boost' => 1.0]]],
                ['match' => ['metadata.similar_queries' => ['query' => $query, 'boost' => 1.5]]],
            ],
        ];
        if ($filterDocType) {
            $boolQuery['filter'] = [['term' => ['metadata.doc_type' => $filterDocType]]];
        }

        $body = [
            'size' => $topK,
            '_source' => ['content', 'metadata'],
            'query' => ['bool' => $boolQuery],
        ];

        if ($useKnn) {
            $body['knn'] = [
                'field' => 'embedding',
                'query_vector' => $queryVector,
                'k' => $topK,
                'num_candidates' => 50,
            ];
            if ($filterDocType) {
                $body['knn']['filter'] = ['term' => ['metadata.doc_type' => $filterDocType]];
            }
        }

        $params = [
            'index' => self::INDEX_NAME,
            'body'  => $body,
            'client' => ['future' => 'lazy'] // Elasticsearch-PHP で非同期(Future)モードを有効化
        ];

        // 1. Elasticsearchのプロミス (Http\Promise\Promise) を取得
        $httpPromise = $this->esClient->search($params);

        // 2. Guzzleのプロミスを作成してラップする (型エラーの解消)
        $guzzlePromise = new GuzzlePromise(function () use ($httpPromise) {
            $httpPromise->wait(); // Utils::all()->wait() が呼ばれた際に実行を強制する
        });

        // 3. Elasticsearchの処理が終わったら、Guzzleのプロミスに結果を渡す
        $httpPromise->then(
            function ($response) use ($guzzlePromise) {
                $res = is_array($response) ? $response : $response->asArray();
                $totalHits = is_array($res["hits"]["total"]) ? $res["hits"]["total"]["value"] : $res["hits"]["total"];
                
                if ($totalHits == 0) {
                    $guzzlePromise->resolve(''); // 結果なし
                    return;
                }

                $lines = [];
                foreach ($res["hits"]["hits"] as $idx => $hit) {
                    $rank = $idx + 1;
                    $source = $hit['_source'];
                    $meta = $source['metadata'] ?? [];
                    $title = $meta['title'] ?? '不明';
                    $docType = $meta['doc_type'] ?? '不明';
                    $content = $source['content'] ?? '';
                    
                    $lines[] = "[文書 {$rank}: {$title} (Type: {$docType})]";
                    $lines[] = $content;
                    $lines[] = ""; 
                }
                
                // 整形したテキストを Guzzle プロミスとして解決する
                $guzzlePromise->resolve(implode("\n", $lines));
            },
            function ($exception) use ($guzzlePromise) {
                // エラー時も Guzzle プロミスに伝播させる
                $guzzlePromise->reject($exception);
            }
        );

        // 戻り値の型 (GuzzlePromiseInterface) に一致させて返す
        return $guzzlePromise;
    }

    /**
     * 指定ディレクトリ以下のMarkdownファイルを全て読み込み、インデックス化する
     */
    public function processAllMarkdowns(string $directoryPath): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directoryPath));
        
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'md') {
                $content = file_get_contents($file->getPathname());
                
                // ドキュメント分類: 親ディレクトリ名をdoc_type、ファイル名をtitleとする
                $docType = basename(dirname($file->getPathname()));
                $title = $file->getBasename('.md');
                $docId = $file->getFilename();

                // チャンク化
                $chunks = $this->chunkText($content, 300, 30);

                foreach ($chunks as $index => $chunkText) {
                    $this->indexDocument($chunkText, [
                        'title' => $title,
                        'doc_id' => $docId,
                        'doc_type' => $docType,
                        'chunk_index' => $index
                    ]);
                }
            }
        }
    }

    /**
     * テキストを単語ベースでチャンク分割する
     * ※日本語環境で文字数ベースにしたい場合は mb_substr 等に変更してください。
     */
    private function chunkText(string $text, int $chunkSize = 300, int $overlap = 30): array
    {
        // 空白・改行区切りで単語の配列にする
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $chunks = [];
        $i = 0;
        
        while ($i < count($words)) {
            $chunkWords = array_slice($words, $i, $chunkSize);
            $chunks[] = implode(' ', $chunkWords);
            $i += ($chunkSize - $overlap);
        }
        
        return $chunks;
    }

    /**
     * 1つのチャンクをベクトル化してElasticsearchに保存する
     */
    private function indexDocument(string $content, array $metadata): void
    {
        $embedding = $this->getEmbedding($content);
        
        $params = [
            'index' => self::INDEX_NAME,
            'body'  => [
                'content'   => $content,
                'embedding' => $embedding,
                'metadata'  => $metadata
            ]
        ];
        
        $this->esClient->index($params);
    }

    private function getEmbedding(string $text): array
    {
        return $this->embeddingProvider->getEmbedding($text);
    }

    /**
     * Elasticsearch の dense_vector + cosine では L2 ノルム 0 のベクトルは使えない。
     */
    private function isZeroMagnitudeVector(array $vector): bool
    {
        $sumSq = 0.0;
        foreach ($vector as $v) {
            $f = (float) $v;
            $sumSq += $f * $f;
        }

        return $sumSq < 1e-20;
    }
}