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
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Laravel\Ripple\Factory;
use Laravel\Ripple\Virtual\Virtual;
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Utils\Output;

use function Co\async;
use function Co\channel;
use function Co\lock;
use function Co\onSignal;
use function Co\wait;

\cli_set_process_title('laravel-ware');
\define("RIP_PROJECT_PATH", \realpath(\getenv('RIP_PROJECT_PATH')));
\define("RIP_BIN_WORKING_PATH", \realpath(\getenv('RIP_BIN_WORKING_PATH')));
require RIP_PROJECT_PATH . '/vendor/autoload.php';
$projectLock    = lock(RIP_PROJECT_PATH);
$projectChannel = channel(RIP_PROJECT_PATH, true);
$application    = Factory::createApplication();
$imagePath      = RIP_BIN_WORKING_PATH . '/ripple-server.bin.php';

if (!$projectLock->exclusion()) {
    Output::warning('Another process is running');
    exit(1);
}

try {
    /*** @var ConsoleKernel $kernel */
    $kernel = $application->make(ConsoleKernel::class);
    $kernel->bootstrap();
    $application->loadDeferredProviders();
} catch (BindingResolutionException $e) {
    Output::error($e->getMessage());
    exit(1);
}

$virtual = new Virtual($imagePath);
$virtual->launch();
$virtual->session->onMessage      = static fn (string $content) => Output::writeln($content);
$virtual->session->onErrorMessage = static fn (string $content) => Output::error($content);

$virtualStop = static function () use (&$virtual) {
    $virtual->channel->send('stop');
    try {
        \Co\sleep(0.1);
        if ($virtual->session->getStatus('running')) {
            \Co\sleep(1);
            $virtual->session->inputSignal(\SIGINT);
        }
    } catch (Throwable) {
    }
    exit(0);
};

$virtualReboot = static function () use (&$virtual, $imagePath) {
    $oldVirtual = $virtual;
    $_virtual   = new Virtual($imagePath);
    $_virtual->launch();
    $oldVirtual->channel->send('stop');
    $virtual                          = $_virtual;
    $virtual->session->onMessage      = static fn (string $content) => Output::writeln($content);
    $virtual->session->onErrorMessage = static fn (string $content) => Output::error($content);
};


async(function () use ($projectChannel, $virtualStop, $virtualReboot) {
    while (1) {
        $command = $projectChannel->receive();
        switch ($command) {
            case 'stop':
                $virtualStop();
                break;

            case 'reload':
                $virtualReboot();
                break;
        }
    }
});

try {
    onSignal(\SIGINT, function () use ($virtualStop) {
        $virtualStop();
    });

    onSignal(\SIGTERM, function () use ($virtualStop) {
        $virtualStop();
    });

    onSignal(\SIGQUIT, function () use ($virtualStop) {
        $virtualStop();
    });
} catch (UnsupportedFeatureException) {
    Output::warning('Failed to register signal handler');
}

wait();
