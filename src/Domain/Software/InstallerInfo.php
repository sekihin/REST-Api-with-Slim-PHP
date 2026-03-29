<?php

declare(strict_types=1);

namespace App\Domain\Software;

/**
 * インストーラー検索結果（値オブジェクト）
 */
final class InstallerInfo
{
    public function __construct(
        private string $name,
        private string $version,
        private string $os,
        private string $url,
        private string $releaseDate,
        private string $notes = ''
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getOs(): string
    {
        return $this->os;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getReleaseDate(): string
    {
        return $this->releaseDate;
    }

    public function getNotes(): string
    {
        return $this->notes;
    }
}
