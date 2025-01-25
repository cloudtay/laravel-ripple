#!/usr/bin/env php
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

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Console\Kernel;
use Laravel\Ripple\Coroutine\ContextManager;
use Laravel\Ripple\Factory;
use Laravel\Ripple\HttpWorker;
use Laravel\Ripple\Util;
use Ripple\Utils\Output;
use Ripple\Worker\Manager;

use function Co\async;
use function Co\channel;
use function Co\wait;

\cli_set_process_title('laravel-virtual');
\define("RIP_PROJECT_PATH", \realpath(\getenv('RIP_PROJECT_PATH')));
\define("RIP_VIRTUAL_ID", \getenv('RIP_VIRTUAL_ID'));
\define("RIP_HTTP_WORKERS", \intval(\getenv('RIP_HTTP_WORKERS')));
\define("RIP_HTTP_LISTEN", \strval(\getenv('RIP_HTTP_LISTEN')));
\define("RIP_WATCH", \boolval(\getenv('RIP_WATCH')));

if (!\function_exists('app')) {
    /**
     * @param string|null $abstract
     * @param array       $parameters
     *
     * @return mixed
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    function app(string $abstract = null, array $parameters = []): mixed
    {
        return ContextManager::app($abstract, $parameters);
    }
}

require RIP_PROJECT_PATH . '/vendor/autoload.php';

/*** LoadDeferredProviders */
try {
    $application = Factory::createApplication();
    $kernel      = $application->make(Kernel::class);
    $kernel->bootstrap();
    $application->loadDeferredProviders();
    /*** @var Manager $manager */
    $manager = $application->make(Manager::class);
} catch (BindingResolutionException $e) {
    Output::error($e->getMessage());
    exit(1);
}

$manager->add($httpWorker = new HttpWorker($application, RIP_HTTP_LISTEN, RIP_HTTP_WORKERS, RIP_WATCH));
$application->singleton(Manager::class, static fn () => $manager);
$application->singleton(HttpWorker::class, static fn () => $httpWorker);

/*** Hot reload part */
$projectChannel           = channel(RIP_PROJECT_PATH);
$includedFiles            = \get_included_files();
$hotReload                = function (string $file) use ($manager, $includedFiles, $projectChannel) {
    if (\in_array($file, $includedFiles, true)) {
        $projectChannel->send('reload');
    } else {
        $manager->reload();
        $date = \date('Y-m-d H:i:s');
        \is_file($file)
        && ($file = Util::getRelativePath($file, RIP_PROJECT_PATH))
        && Output::writeln("[{$date}] {$file} has been modified");
    }
};
$hotReloadWatch           = Factory::createMonitor();
$hotReloadWatch->onModify = $hotReload;
$hotReloadWatch->onTouch  = $hotReload;
$hotReloadWatch->onRemove = $hotReload;
if (RIP_WATCH) {
    $hotReloadWatch->run();
}

/*** Guardian part*/
async(function () use ($manager) {
    $channel = channel(RIP_VIRTUAL_ID, true);
    while (1) {
        $control = $channel->receive();
        if ($control === 'stop') {
            $manager->terminate();
            exit(0);
        }

        if ($control === 'reload') {
            $manager->reload();
        }
    }
});

/*** start */
Output::info("[laravel-ripple]", 'started');
$manager->run();
wait();
