<?php

declare(strict_types=1);

namespace App\Domain\Knowledge;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Elastic\Elasticsearch\Client as ElasticsearchClient;
use stdClass;

class KnowledgeBaseService
{
    private ElasticsearchClient $esClient;
    private HttpClient $httpClient;
    private string $doubaoApiKey;

    private const DOUBAO_HOST = "ark.cn-beijing.volces.com";
    private const EMBEDDING_PATH = "/api/v3/embeddings/multimodal";
    private const EMBEDDING_MODEL = "doubao-embedding-vision-250615";
    private const EMBEDDING_DIM = 2048;
    private const INDEX_NAME = "rag_documents";

    public function __construct(ElasticsearchClient $esClient, string $doubaoApiKey)
    {
        $this->esClient = $esClient;
        $this->doubaoApiKey = $doubaoApiKey;
        $this->httpClient = new HttpClient([
            'base_uri' => 'https://' . self::DOUBAO_HOST,
            'timeout'  => 30.0,
        ]);
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
                            'source' => "cosineSimilarity(params.query_vector, 'embedding') + 1.0",
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
     * 検索クエリをDoubao APIでベクトル化
     */
    private function getEmbedding(string $text): array
    {
        $text = trim($text);
        if (empty($text)) {
            return array_fill(0, self::EMBEDDING_DIM, 0.0);
        }

        try {
            $payload = [
                "model" => self::EMBEDDING_MODEL,
                "input" => [["type" => "text", "text" => $text]]
            ];

            $response = $this->httpClient->post(self::EMBEDDING_PATH, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->doubaoApiKey,
                    'Content-Type'  => 'application/json; charset=utf-8'
                ],
                'json' => $payload
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result["data"]["embedding"]) && is_array($result["data"]["embedding"])) {
                $embedding = $result["data"]["embedding"];
                $len = count($embedding);
                
                // 次元数の調整
                if ($len > self::EMBEDDING_DIM) {
                    return array_slice($embedding, 0, self::EMBEDDING_DIM);
                } elseif ($len < self::EMBEDDING_DIM) {
                    return array_merge($embedding, array_fill(0, self::EMBEDDING_DIM - $len, 0.0));
                }
                return $embedding;
            }

            return array_fill(0, self::EMBEDDING_DIM, 0.0);

        } catch (RequestException $e) {
            error_log("Doubao API Error: " . $e->getMessage());
            return array_fill(0, self::EMBEDDING_DIM, 0.0);
        }
    }
}