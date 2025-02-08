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
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use Laravel\Ripple\Built\Factory;
use Laravel\Ripple\HttpWorker;
use Laravel\Ripple\Octane\RippleClient;
use Laravel\Ripple\Util;
use Ripple\Http\Server\Request;
use Ripple\Utils\Output;
use Ripple\Worker\Manager;

use function Co\async;
use function Co\channel;
use function Co\wait;

\cli_set_process_title('laravel-virtual');
\define("RIP_PROJECT_PATH", \strval(\realpath(\getenv('RIP_PROJECT_PATH'))));
\define("RIP_VIRTUAL_ID", \strval(\getenv('RIP_VIRTUAL_ID')));
\define("RIP_HOST", \strval(\getenv('RIP_HOST')));
\define("RIP_PORT", \intval(\getenv('RIP_PORT')));
\define("RIP_WORKERS", \intval(\getenv('RIP_WORKERS')));
\define("RIP_WATCH", \boolval(\getenv('RIP_WATCH')));

\define('RIP_HTTP_LISTEN', 'http://' . RIP_HOST . ':' . RIP_PORT);
\define('RIP_HTTP_WORKERS', RIP_WORKERS);

require RIP_PROJECT_PATH . '/vendor/autoload.php';

/*** Octane part */
$octaneWorker   = new Worker(
    new ApplicationFactory(RIP_PROJECT_PATH),
    $octaneClient = new RippleClient()
);
$octaneWorker->boot();

/*** @var Manager $manager */
$application = $octaneWorker->application();
try {
    $manager    = $application->make(Manager::class);
    $httpWorker = new HttpWorker($application, RIP_HTTP_LISTEN, RIP_HTTP_WORKERS, RIP_WATCH);
    $application->singleton(Manager::class, static fn () => $manager);
    $application->singleton(HttpWorker::class, static fn () => $httpWorker);
} catch (BindingResolutionException $e) {
    Output::error($e->getMessage());
    exit(1);
}

$httpWorker->customHandler(static function (Request $rippleRequest) use ($octaneClient, $octaneWorker) {
    $octaneWorker->handle(
        ...$octaneClient->marshalRequest(new RequestContext(['rippleRequest' => $rippleRequest]))
    );
});
$manager->add($httpWorker);

/*** Hot reload part */
$projectChannel = channel(RIP_PROJECT_PATH);
$includedFiles  = \get_included_files();
$hotReload      = static function (string $file) use ($manager, $includedFiles, $projectChannel) {
    if (\in_array($file, $includedFiles, true)) {
        Output::write("\033c");
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
async(static function () use ($manager) {
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
