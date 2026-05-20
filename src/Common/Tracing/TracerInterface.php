<?php

declare(strict_types=1);

namespace App\Common\Tracing;

/**
 * パフォーマンストレースインターフェース（PerfTrace の密結合を解消し、注入可能な依存関係に変更）
 */
interface TracerInterface
{
    /** 現在のタイムスタンプを返す（時間計測の起点として使用） */
    public function now(): mixed;

    /**
     * 経過時間を記録する
     *
     * @param string $name    メトリクス名（例: 'LLM.DoubaoChat'）
     * @param mixed  $start   now() から返された開始時間
     * @param array  $context 追加のコンテキスト（モデル、トークン数など）
     */
    public function log(string $name, mixed $start, array $context = []): void;
}
