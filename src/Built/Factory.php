<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Laravel\Ripple\Built;

use FilesystemIterator;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Ripple\File\File;
use Ripple\File\Monitor;

use function is_dir;
use function array_shift;
use function is_file;

class Factory
{
    /**
     * @return \Illuminate\Foundation\Application
     */
    public static function createApplication(): Application
    {
        return Application::configure(basePath: RIP_PROJECT_PATH)
            ->withRouting(
                web: RIP_PROJECT_PATH . '/routes/web.php',
                commands: RIP_PROJECT_PATH . '/routes/console.php',
                health: '/up',
            )
            ->withMiddleware()
            ->withExceptions()
            ->create();
    }

    /**
     * @return array
     */
    public static function initializeServices(): array
    {
        return [
            //            'auth',
            //            'cache',
            'cache.store',
            'config',
            'cookie',
            'db',
            'db.factory',
            'db.transactions',
            'encrypter',
            'files',
            'hash',
            'log',
            'router',
            'routes',
            //            'session',
            'session.store',
            'translator',
            //            'url',
            'view',
        ];
    }

    /**
     * @return string[]
     */
    public static function defaultServicesToWarm(): array
    {
        return [
            'auth',
            'cache',
            'cache.store',
            'config',
            'cookie',
            'db',
            'db.factory',
            'db.transactions',
            'encrypter',
            'files',
            'hash',
            'log',
            'router',
            'routes',
            'session',
            'session.store',
            'translator',
            'url',
            'view',
        ];
    }

    /**
     * @return \Ripple\File\Monitor
     */
    public static function createMonitor(): Monitor
    {
        $monitor    = File::getInstance()->monitor();
        $watchPaths = Config::get('ripple.WATCH_PATHS', []);

        $after = [];

        foreach ($watchPaths as $path) {
            if (is_file($path)) {
                $monitor->add($path);
            }

            if (is_dir($path)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                /*** @var \SplFileInfo $file */
                foreach ($iterator as $file) {
                    if ($file->isDir()) {
                        $after[] = $file->getPathname();
                    }

                    if ($file->isFile()) {
                        $monitor->add($file->getPathname());
                    }
                }

                $after[] = $path;
            }

            while ($path = array_shift($after)) {
                $monitor->add($path);
            }
        }

        return $monitor;
    }
}
