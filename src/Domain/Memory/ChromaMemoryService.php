<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use App\Domain\Knowledge\EmbeddingProviderInterface;

/**
 * Chroma DB を使用したユーザー記憶（Vector DB）管理サービス
 */
class ChromaMemoryService
{
    private string $chromaUrl;
    private string $collectionName = 'user_memories';

    public function __construct(
        private EmbeddingProviderInterface $embeddingProvider
    ) {
        // .env 等から Chroma DB のホストURLを取得 (例: http://localhost:8000)
        // Slim アプリでは Laravel の config() helper が存在しないため、環境変数を優先して参照する。
        $this->chromaUrl = getenv('CHROMA_URL') ?: 'http://localhost:8000';
    }

    /**
     * ユーザーの事実をベクトル化して Chroma DB に保存する
     */
    public function storeMemory(string $userId, string $fact): void
    {
        // 1. テキストをエンベディング（ベクトル化）する
        // 例: OpenAI の text-embedding-3-small 等を呼び出す
        $embedding = $this->embeddingProvider->getEmbedding($fact);

        // 2. コレクションのIDを取得（なければ作成）
        $collectionId = $this->getOrCreateCollection();

        // 3. Chroma DB の Add エンドポイントへデータを送信
        // 複数の一括保存に対応していますが、今回は1件ずつ追加する想定です
        $response = Http::post("{$this->chromaUrl}/api/v1/collections/{$collectionId}/add", [
            'ids' => [uniqid('mem_')], // 記憶の一意なID
            'embeddings' => [$embedding], // 生成したベクトル配列
            'documents' => [$fact], // 抽出された事実のテキスト
            'metadatas' => [
                ['user_id' => $userId, 'created_at' => time()] // 検索時にユーザーを絞り込むためのメタデータ
            ],
        ]);

        if ($response->failed()) {
            throw new \Exception('Chroma DB への保存に失敗しました: ' . $response->body());
        }
    }

    /**
     * コレクション（テーブルのようなもの）のIDを取得・作成する内部メソッド
     */
    private function getOrCreateCollection(): string
    {
        $response = Http::post("{$this->chromaUrl}/api/v1/collections", [
            'name' => $this->collectionName,
            'get_or_create' => true,
        ]);

        if ($response->failed()) {
            throw new \Exception('Chroma DB コレクションの取得に失敗しました。');
        }

        return $response->json('id');
    }

    /**
     * 同期で記憶を検索する (コントローラーの古い呼び出しとの互換性用)
     * searchMemories() のエイリアスとして機能します。
     */
    public function search(string $userId, string $query, int $topK = 5): string
    {
        return $this->searchMemories($userId, $query, $topK);
    }
    
    /**
     * 同期で記憶を検索する (Undefined method 'search' の解消用)
     * * @param string $userId ユーザーID
     * @param string $query 検索クエリ
     * @param int $topK 取得する件数
     * @return string 検索された事実の文字列
     */
    public function searchMemories(string $userId, string $query, int $topK = 5): string
    {
        $queryEmbedding = $this->embeddingProvider->getEmbedding($query);
        $collectionId = $this->getOrCreateCollection();

        // Http::post を使って同期でリクエストを送信し、結果を待ちます
        $response = \Illuminate\Support\Facades\Http::post("{$this->chromaUrl}/api/v1/collections/{$collectionId}/query", [
            'query_embeddings' => [$queryEmbedding],
            'n_results' => $topK,
            'where' => ['user_id' => $userId],
        ]);

        if ($response->failed()) {
            return ''; // エラー時は空文字を返してAIに「記憶なし」と認識させる
        }

        $results = $response->json();
        
        // 該当する記憶が見つからない場合
        if (empty($results['documents'][0])) {
            return '';
        }

        // 見つかった記憶の配列を改行でつなげて文字列にして返す
        return implode("\n", $results['documents'][0]);
    }

    /**
     * 会話のやり取りから新たな事実を抽出し、記憶として保存する（非同期実行）
     * RagController の Step 7.5 から呼び出されます。
     */
    public function add(string $userId, string $userMessage, string $assistantReply): void
    {
        // LLM呼び出しとChromaDBへの保存処理(storeMemory)をバックグラウンド(Queue)に投げる
        // Slim 環境では Laravel Queue が構成されていない可能性があるため、失敗しても API を落とさない。
        try {
            // 重要:
            // - ExtractUserMemoryJob は Laravel Queue の trait/interface に依存するため、
            //   依存が無い環境で autoload すると Fatal error になる。
            // - そのため、Laravel Queue に必要なクラス/trait が揃っている場合のみ Job をロードする。
            $laravelQueueDeps = [
                'Illuminate\\Foundation\\Bus\\Dispatchable',
                'Illuminate\\Bus\\Queueable',
                'Illuminate\\Queue\\InteractsWithQueue',
                'Illuminate\\Queue\\SerializesModels',
                'Illuminate\\Contracts\\Queue\\ShouldQueue',
            ];
            foreach ($laravelQueueDeps as $dep) {
                if (!class_exists($dep, false) && !trait_exists($dep, false) && !interface_exists($dep, false)) {
                    return;
                }
            }

            $jobFqcn = 'App\\Infrastructure\\AI\\Jobs\\ExtractUserMemoryJob';

            // ここで初めて autoload を許可する（依存が揃っている前提）
            if (!class_exists($jobFqcn)) {
                return;
            }

            $jobFqcn::dispatch($userId, $userMessage, $assistantReply);
        } catch (\Throwable $e) {
            // ignore failures in Slim environments without queue
        }
    }

    /**
     * 非同期(Promise)で記憶を検索する
     */
    public function searchMemoriesAsync(string $userId, string $query, int $topK = 5): PromiseInterface
    {
        $queryEmbedding = $this->embeddingProvider->getEmbedding($query);
        $collectionId = $this->getOrCreateCollection();

        // Http::async() を使って Promise を返す (LaravelのHttpクライアント)
        return \Illuminate\Support\Facades\Http::async()->post("{$this->chromaUrl}/api/v1/collections/{$collectionId}/query", [
            'query_embeddings' => [$queryEmbedding],
            'n_results' => $topK,
            'where' => ['user_id' => $userId],
        ])->then(function ($response) {
            $results = $response->json();
            if (empty($results['documents'][0])) {
                return '';
            }
            return implode("\n", $results['documents'][0]);
        });
    }
}