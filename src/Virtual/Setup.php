<?php declare(strict_types=1);

namespace Ripple\Driver\Laravel\Virtual;

use Illuminate\Foundation\Application;

class Setup
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
}
