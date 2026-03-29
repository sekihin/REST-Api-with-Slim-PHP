<?php

declare(strict_types=1);

namespace App\Domain\Software;

/**
 * ソフトウェア／インストーラー情報の取得（スタブ実装）
 * 永続化や外部APIが整ったら findInstaller を実装してください。
 */
class SoftwareService
{
    public function findInstaller(string $softwareName, string $osType, string $version): ?InstallerInfo
    {
        return null;
    }
}
