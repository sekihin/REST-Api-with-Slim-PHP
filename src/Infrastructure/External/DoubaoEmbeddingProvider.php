<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use App\Domain\Knowledge\EmbeddingProviderInterface;

/**
 * Doubao (豆包) 専用 Embedding プロバイダー
 *
 * RAG / ナレッジベース用のベクトル化のみを担当します。
 * 内部で DoubaoProvider の getEmbedding を利用します。
 */
class DoubaoEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private DoubaoProvider $doubao,
    ) {
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
