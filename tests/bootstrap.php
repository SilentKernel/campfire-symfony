<?php

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Each PHPUnit process compiles its own container (Kernel::getCacheDir honours APP_CACHE_DIR):
// runs in parallel (several checkouts, agents, the dev image with the repo mounted) never share
// or race on a half-written var/cache/test, and a run never reuses a container compiled from
// other sources. Set APP_CACHE_DIR yourself to keep a cache between runs.
if (!isset($_SERVER['APP_CACHE_DIR'])) {
    $cacheDir = dirname(__DIR__).'/var/cache/phpunit-'.getmypid().'-'.bin2hex(random_bytes(4));
    $_SERVER['APP_CACHE_DIR'] = $_ENV['APP_CACHE_DIR'] = $cacheDir;
    putenv('APP_CACHE_DIR='.$cacheDir);
    $pid = getmypid();
    register_shutdown_function(static function () use ($cacheDir, $pid): void {
        if (getmypid() === $pid) {
            (new Filesystem())->remove($cacheDir);
        }
    });
}
