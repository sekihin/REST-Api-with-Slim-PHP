<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use Psr\Log\LoggerInterface;
use App\Domain\Knowledge\EmbeddingProviderInterface;

/**
 * Doubao (豆包) 専用 Embedding プロバイダー
 *
 * RAG / ナレッジベース用のベクトル化のみを担当します。
 * 内部で DoubaoProvider の getEmbedding を利用します。
 */
class DoubaoEmbeddingProvider implements EmbeddingProviderInterface
{
    private DoubaoProvider $doubao;

    public function __construct(string $apiKey, ?LoggerInterface $logger = null)
    {
        $this->doubao = new DoubaoProvider(
            $apiKey,
            $logger ?? new \Monolog\Logger('doubao-embedding'),
            'doubao-seed-2-0-mini-260215',
            0.5,
            2048,
            60,
            3
        );
    }

    /**
     * テキストをEmbeddingベクトルに変換
     *
     * @return array<float> 埋め込みベクトル
     */
    public function getEmbedding(string $text): array
    {
        return $this->doubao->getEmbedding($text);
    }
}
