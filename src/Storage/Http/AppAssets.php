<?php

declare(strict_types=1);

namespace App\Storage\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Files under app/assets/images that controllers `send_file` (the default bot avatar, the stock
 * app icons). They live in assets/images, the AssetMapper source of the same files.
 */
final readonly class AppAssets
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDir)
    {
    }

    public function image(string $logicalPath): string
    {
        return $this->projectDir.'/assets/images/'.$logicalPath;
    }
}
