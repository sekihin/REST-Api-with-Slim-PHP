<?php

declare(strict_types=1);

namespace App\Domain\Knowledge;

/**
 * RAG / ベクトル検索用の埋め込みプロバイダー契約
 */
interface EmbeddingProviderInterface
{
    /**
     * テキストを埋め込みベクトルに変換する
     *
     * @return array<float>
     */
    public function getEmbedding(string $text): array;
}
