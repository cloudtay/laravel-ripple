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

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Laravel\Ripple\Monitor;

use function is_dir;
use function is_file;

class Factory
{
    /**
     * @return Application
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
     * @return Monitor
     */
    public static function createMonitor(): Monitor
    {
        $monitor = new Monitor();
        $watchPaths = Config::get('ripple.WATCH_PATHS', []);

        foreach ($watchPaths as $path) {
            if (is_file($path)) {
                $monitor->add($path);
            }

            if (is_dir($path)) {
                $monitor->add($path, 'php');
            }
        }

        return $monitor;
    }
}
