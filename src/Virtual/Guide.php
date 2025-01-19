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
use JetBrains\PhpStorm\NoReturn;
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Driver\Laravel\Coroutine\ContextManager;
use Ripple\Driver\Laravel\Factory;
use Ripple\Driver\Laravel\HttpWorker;
use Ripple\Utils\Output;
use Ripple\Worker\Manager;

use function Co\async;
use function Co\channel;
use function Co\onSignal;
use function Co\wait;

\cli_set_process_title('laravel-guard');

/**
 * @param string $message
 *
 * @return void
 */
#[NoReturn]
function _rip_error_terminate(string $message = ''): void
{
    if ($message) {
        \fwrite(\STDOUT, $message . \PHP_EOL);
    }
    exit(1);
}

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

/**
 * @return \Ripple\Driver\Laravel\HttpWorker
 * @throws \Illuminate\Contracts\Container\BindingResolutionException
 */
function rippleHttpWorker(): HttpWorker
{
    return \app('rippleHttpWorker');
}

$projectPath = \getenv('RIP_PROJECT_PATH') ?? null;
$projectPath || \_rip_error_terminate("the RIP_PROJECT_PATH environment variable is not set");
$projectVirtualId = \getenv('RIP_VIRTUAL_ID') ?? null;
$projectVirtualId || \_rip_error_terminate("the RIP_VIRTUAL_ID environment variable is not set");
\define("RIP_PROJECT_PATH", \realpath($projectPath));
\define("RIP_VIRTUAL_ID", $projectVirtualId);

\is_dir($projectPath) || \_rip_error_terminate("the RIP_PROJECT_PATH environment variable is not a valid directory");
\is_file("{$projectPath}/vendor/autoload.php") || \_rip_error_terminate("the vendor/autoload.php file was not found in the project directory");

require RIP_PROJECT_PATH . '/vendor/autoload.php';

$application = Factory::createApplication();

try {
    $kernel = $application->make(Kernel::class);
    $kernel->bootstrap();
    $application->loadDeferredProviders();
    /*** @var Manager $manager */
    $manager = $application->make(Manager::class);
} catch (BindingResolutionException $e) {
    \_rip_error_terminate("kernel resolution failed: {$e->getMessage()}");
}

$manager->addWorker(new HttpWorker($application));
$manager->run();

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

wait();
