<?php

declare(strict_types=1);

namespace App\Domain\Knowledge;

use Elastic\Elasticsearch\Client as ElasticsearchClient;
use stdClass;
use App\Domain\Knowledge\EmbeddingProviderInterface;

class KnowledgeBaseService
{
    private ElasticsearchClient $esClient;
    private EmbeddingProviderInterface $embeddingProvider;

    private const EMBEDDING_DIM = 2048;
    private const INDEX_NAME = "rag_documents";

    public function __construct(ElasticsearchClient $esClient, EmbeddingProviderInterface $embeddingProvider)
    {
        $this->esClient = $esClient;
        $this->embeddingProvider = $embeddingProvider;
    }

    /**
     * ベクトル検索を実行し、整形済みの配列を返す
     */
    public function searchDocuments(string $query, int $topK = 3): array
    {
        // 1. クエリのベクトル化
        $queryVector = $this->getEmbedding($query);

        // 2. Elasticsearch の検索クエリ構築
        $params = [
            'index' => self::INDEX_NAME,
            'body'  => [
                'size'    => $topK,
                '_source' => ["content", "metadata"],
                'query'   => [
                    'script_score' => [
                        'query'  => ['match_all' => new stdClass()],
                        'script' => [
                            'source' => "double score = cosineSimilarity(params.query_vector, 'embedding'); if (Double.isNaN(score) || Double.isInfinite(score)) { score = 0.0; } return score + 1.0;",
                            'params' => ['query_vector' => $queryVector]
                        ]
                    ]
                ]
            ]
        ];

        // 3. 検索実行
        $response = $this->esClient->search($params);
        $res = $response->asArray();

        $totalHits = is_array($res["hits"]["total"]) ? $res["hits"]["total"]["value"] : $res["hits"]["total"];
        if ($totalHits == 0) {
            return [];
        }

        // 4. 結果のフォーマット
        $formattedResults = [];
        foreach ($res["hits"]["hits"] as $hit) {
            $source = $hit['_source'];
            $meta = $source['metadata'] ?? [];

            $formattedResults[] = [
                'title'   => $meta['title'] ?? '不明',
                'section' => $meta['section'] ?? '不明',
                'doc_id'  => $meta['document_id'] ?? '不明',
                'content' => $source['content'] ?? '',
                'score'   => $hit['_score']
            ];
        }

        return $formattedResults;
    }

    /**
     * 検索クエリを埋め込みベクトルに変換
     */
    private function getEmbedding(string $text): array
    {
        return $this->embeddingProvider->getEmbedding($text);
    }
}