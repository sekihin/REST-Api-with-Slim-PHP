<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use Psr\Log\LoggerInterface;
use App\Domain\Knowledge\EmbeddingProviderInterface;

/**
 * Google Gemini 専用 Embedding プロバイダー
 *
 * RAG / ナレッジベース / 長期記憶用のベクトル化のみを担当します。
 * 内部で GeminiProvider の getEmbedding を利用します。
 */
class GeminiEmbeddingProvider implements EmbeddingProviderInterface
{
    private GeminiProvider $gemini;

    /**
     * コンストラクタ
     *
     * @param string $apiKey Google AI Studio で取得したAPIキー
     * @param LoggerInterface|null $logger
     */
    public function __construct(string $apiKey, ?LoggerInterface $logger = null)
    {
        $timeout = getenv('GEMINI_TIMEOUT') !== false
            ? (int) getenv('GEMINI_TIMEOUT')
            : 60;
            
        $maxRetries = getenv('GEMINI_MAX_RETRIES') !== false
            ? (int) getenv('GEMINI_MAX_RETRIES')
            : 3;

        // Embedding専用としてラップするため、チャット用のパラメータ（chatModel, temperature）はデフォルト値や無難な値を指定します
        $this->gemini = new GeminiProvider(
            apiKey:      $apiKey,
            logger:      $logger ?? new \Monolog\Logger('gemini-embedding'),
            chatModel:   'gemini-1.5-flash', // Embeddingでは使用されません
            temperature: 0.0,                // Embeddingでは使用されません
            timeout:     $timeout,
            maxRetries:  $maxRetries
        );
    }

    /**
     * テキストをEmbeddingベクトルに変換
     *
     * @param string $text ベクトル化するテキスト
     * @return array<float> 埋め込みベクトル (Gemini embedding-001 の場合は通常768次元)
     */
    public function getEmbedding(string $text): array
    {
        return $this->gemini->getEmbedding($text);
    }
}