<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use GuzzleHttp\Promise\PromiseInterface as GuzzlePromiseInterface;
use GuzzleHttp\Promise\Promise as GuzzlePromise;
use Elastic\Elasticsearch\Client as ElasticsearchClient;
use App\Common\PerfTrace;
use App\Domain\Knowledge\EmbeddingProviderInterface;

/**
 * Elasticsearch を使用したユーザー記憶（Vector DB）管理サービス
 * 24/7の本番稼働を前提とした堅牢なアーキテクチャ
 */
class ElasticsearchMemoryService
{
    private const INDEX_NAME = 'user_memories';
    
    // ご利用のエンベディングモデルに合わせて次元数を変更してください
    // (例: text-embedding-3-small なら 1536)
    private const EMBEDDING_DIM = 1536; 

    public function __construct(
        private ElasticsearchClient $esClient,
        private EmbeddingProviderInterface $embeddingProvider
    ) {
    }

    /**
     * やり取りから事実を抽出し保存する (非同期Jobへ投げる)
     */
    public function add(string $userId, string $userMessage, string $assistantReply): void
    {
        // Slim 環境では Laravel Queue 依存が無い場合があるため、
        // Job クラスのロード前に依存を厳密にチェックし、Fatal を回避する。
        $laravelQueueDeps = [
            'Illuminate\\Foundation\\Bus\\Dispatchable',
            'Illuminate\\Bus\\Queueable',
            'Illuminate\\Queue\\InteractsWithQueue',
            'Illuminate\\Queue\\SerializesModels',
            'Illuminate\\Contracts\\Queue\\ShouldQueue',
        ];
        foreach ($laravelQueueDeps as $dep) {
            $available = class_exists($dep, false) || trait_exists($dep, false) || interface_exists($dep, false);
            if (!$available) {
                return;
            }
        }

        $jobFqcn = 'App\\Infrastructure\\AI\\Jobs\\ExtractUserMemoryJob';
        if (!class_exists($jobFqcn)) {
            return;
        }

        $jobFqcn::dispatch($userId, $userMessage, $assistantReply);
    }

    /**
     * ユーザーの事実をベクトル化して Elasticsearch に保存する
     */
    public function storeMemory(string $userId, string $fact): void
    {
        $this->ensureIndexExists();

        $embedding = $this->embeddingProvider->getEmbedding($fact);

        $params = [
            'index' => self::INDEX_NAME,
            'id'    => uniqid('mem_'), // IDを手動で採番
            'body'  => [
                'content'    => $fact,
                'embedding'  => $embedding,
                'user_id'    => $userId,
                'created_at' => time()
            ]
        ];

        $this->esClient->index($params);
    }

    /**
     * 同期で記憶を検索する
     */
    public function search(string $userId, string $query, int $topK = 5): string
    {
        return $this->searchMemories($userId, $query, $topK);
    }

    /**
     * 同期で記憶を検索する
     */
    public function searchMemories(string $userId, string $query, int $topK = 5): string
    {
        $tEmbedding = PerfTrace::now();
        $queryVector = $this->embeddingProvider->getEmbedding($query);
        PerfTrace::log('Memory.Embedding', $tEmbedding, [
            'user_id' => $userId,
            'query_length' => strlen($query),
            'vector_dims' => count($queryVector),
        ]);

        $params = [
            'index' => self::INDEX_NAME,
            'body'  => $this->buildKnnQuery($userId, $queryVector, $topK)
        ];

        try {
            $tEs = PerfTrace::now();
            $response = $this->esClient->search($params);
            $res = $response->asArray();

            $totalHits = is_array($res["hits"]["total"]) ? $res["hits"]["total"]["value"] : $res["hits"]["total"];
            PerfTrace::log('Memory.ElasticsearchSearch', $tEs, [
                'index' => self::INDEX_NAME,
                'user_id' => $userId,
                'top_k' => $topK,
                'hits' => $totalHits,
            ]);
            
            if ($totalHits == 0) {
                return '';
            }

            $facts = [];
            foreach ($res["hits"]["hits"] as $hit) {
                $facts[] = $hit['_source']['content'];
            }

            return implode("\n", $facts);

        } catch (\Throwable $e) {
            // エラー時はフォールバックとして空文字を返す
            \Illuminate\Support\Facades\Log::error('ES Memory Search Error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 非同期(Promise)で記憶を検索する
     */
    public function searchMemoriesAsync(string $userId, string $query, int $topK = 5): GuzzlePromiseInterface
    {
        $tEmbedding = PerfTrace::now();
        $queryVector = $this->embeddingProvider->getEmbedding($query);
        PerfTrace::log('Memory.Embedding', $tEmbedding, [
            'user_id' => $userId,
            'query_length' => strlen($query),
            'vector_dims' => count($queryVector),
            'mode' => 'async',
        ]);

        $params = [
            'index'  => self::INDEX_NAME,
            'body'   => $this->buildKnnQuery($userId, $queryVector, $topK),
            'client' => ['future' => 'lazy'] // Elasticsearchの非同期モード
        ];

        $tEs = PerfTrace::now();
        $httpPromise = $this->esClient->search($params);

        // Guzzle プロミスへのラップ（コントローラー側での Utils::all() のための型合わせ）
        $guzzlePromise = new GuzzlePromise(function () use ($httpPromise) {
            $httpPromise->wait();
        });

        $httpPromise->then(
            function ($response) use ($guzzlePromise, $userId, $topK, $tEs) {
                $res = is_array($response) ? $response : $response->asArray();
                $totalHits = is_array($res["hits"]["total"]) ? $res["hits"]["total"]["value"] : $res["hits"]["total"];
                PerfTrace::log('Memory.ElasticsearchSearch', $tEs, [
                    'index' => self::INDEX_NAME,
                    'user_id' => $userId,
                    'top_k' => $topK,
                    'hits' => $totalHits,
                    'mode' => 'async',
                ]);
                
                if ($totalHits == 0) {
                    $guzzlePromise->resolve('');
                    return;
                }

                $facts = [];
                foreach ($res["hits"]["hits"] as $hit) {
                    $facts[] = $hit['_source']['content'];
                }
                
                $guzzlePromise->resolve(implode("\n", $facts));
            },
            function ($exception) use ($guzzlePromise) {
                \Illuminate\Support\Facades\Log::error('ES Async Memory Search Error: ' . $exception->getMessage());
                // エラー時は処理を止めず、空文字として解決させる（フォールバック）
                $guzzlePromise->resolve('');
            }
        );

        return $guzzlePromise;
    }

    /**
     * KNN検索用のクエリボディを構築するヘルパー
     */
    private function buildKnnQuery(string $userId, array $queryVector, int $topK): array
    {
        return [
            'size' => $topK,
            '_source' => ['content'],
            'knn' => [
                'field' => 'embedding',
                'query_vector' => $queryVector,
                'k' => $topK,
                'num_candidates' => 50,
                // 他のユーザーの記憶が混ざらないように確実にフィルタリング
                'filter' => [
                    'term' => [
                        'user_id' => $userId
                    ]
                ]
            ]
        ];
    }

    /**
     * インデックスが存在しない場合は、ベクトル検索用のマッピング付きで作成する
     */
    private function ensureIndexExists(): void
    {
        $params = ['index' => self::INDEX_NAME];

        if (!$this->esClient->indices()->exists($params)->asBool()) {
            $this->esClient->indices()->create([
                'index' => self::INDEX_NAME,
                'body' => [
                    'mappings' => [
                        'properties' => [
                            'content' => ['type' => 'text'],
                            'user_id' => ['type' => 'keyword'], // 完全一致検索用
                            'created_at' => ['type' => 'date', 'format' => 'epoch_second'],
                            'embedding' => [
                                'type' => 'dense_vector',
                                'dims' => self::EMBEDDING_DIM,
                                'index' => true,
                                'similarity' => 'cosine'
                            ]
                        ]
                    ]
                ]
            ]);
        }
    }

}