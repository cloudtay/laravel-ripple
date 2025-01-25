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
use Illuminate\Support\Facades\Config;
use Laravel\Ripple\Factory;
use Laravel\Ripple\HttpWorker;
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Utils\Output;
use Ripple\Worker\Manager;

use function Co\async;
use function Co\channel;
use function Co\onSignal;
use function Co\wait;

\cli_set_process_title('laravel-virtual');
\define("RIP_PROJECT_PATH", \realpath(\getenv('RIP_PROJECT_PATH')));
\define("RIP_VIRTUAL_ID", \getenv('RIP_VIRTUAL_ID'));
require RIP_PROJECT_PATH . '/vendor/autoload.php';

/*** loadDeferredProviders */
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

$manager->addWorker(new HttpWorker(
    $application,
    \strval(Config::get('ripple.HTTP_LISTEN', 'http://127.0.0.1:8008')),
    \intval(Config::get('ripple.HTTP_WORKER_COUNT', 1)),
    \boolval(Config::get('ripple.HTTP_RELOAD', true))
));

/*** Hot reload part */
$projectChannel           = channel(RIP_PROJECT_PATH);
$includedFiles            = \get_included_files();
$hotReload                = function (string $file) use ($manager, $includedFiles, $projectChannel) {
    if (\in_array($file, $includedFiles, true)) {
        $projectChannel->send('reload');
    } else {
        $manager->reload();
        $date = \date('Y-m-d H:i:s');
        \is_file($file) && Output::writeln("[{$date}] {$file} has been modified");
    }
};
$hotReloadWatch           = Factory::createMonitor();
$hotReloadWatch->onModify = $hotReload;
$hotReloadWatch->onTouch  = $hotReload;
$hotReloadWatch->onRemove = $hotReload;
$hotReloadWatch->run();

/*** Guardian part*/
async(function () use ($manager) {
    $channel = channel(RIP_VIRTUAL_ID, true);
    try {
        onSignal(\SIGINT, function () use ($manager) {
            $manager->stop();
            exit(0);
        });

        onSignal(\SIGTERM, function () use ($manager) {
            $manager->stop();
            exit(0);
        });

        onSignal(\SIGQUIT, function () use ($manager) {
            $manager->stop();
            exit(0);
        });
    } catch (UnsupportedFeatureException) {
        Output::warning('Failed to register signal handler');
    }

    while (1) {
        $control = $channel->receive();
        if ($control === 'stop') {
            $manager->stop();
            exit(0);
        }
    }
});

/*** start */
Output::info("[laravel-ripple]", 'started');
$manager->run();
wait();
