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

use Composer\InstalledVersions;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Console\Kernel;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use Laravel\Ripple\Built\Coroutine\ContextManager;
use Laravel\Ripple\Built\Factory;
use Laravel\Ripple\HttpWorker;
use Laravel\Ripple\Octane\RippleClient;
use Laravel\Ripple\Util;
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Channel\Channel;
use Ripple\Http\Server\Request;
use Ripple\Utils\Output;
use Ripple\Worker\Manager;

use function Co\async;
use function Co\channel;
use function Co\onSignal;
use function Co\repeat;
use function Co\wait;

\cli_set_process_title('laravel-virtual');
\define("RIP_PROJECT_PATH", \realpath(\getenv('RIP_PROJECT_PATH')));
\define("RIP_VIRTUAL_ID", \getenv('RIP_VIRTUAL_ID'));

\define("RIP_HOST", \strval(\getenv('RIP_HOST')));
\define("RIP_PORT", \intval(\getenv('RIP_PORT')));
\define("RIP_WATCH", \boolval(\getenv('RIP_WATCH')));

\define("RIP_HTTP_WORKERS", \intval(\getenv('RIP_HTTP_WORKERS')));
\define("RIP_HTTP_LISTEN", \strval(\getenv('RIP_HTTP_LISTEN')));
\define("RIP_HOOK", \boolval(\getenv('RIP_HOOK')));

/**
 * @param \Ripple\Channel\Channel $projectChannel
 *
 * @return void
 */
function __rip_restart(Channel $projectChannel): void
{
    $projectChannel->send('restart');
}

/**
 * @param \Ripple\Worker\Manager $manager
 *
 * @return void
 */
function __rip_reload(Manager $manager): void
{
    $manager->reload();
}

if (RIP_HOOK) {
    if (!\function_exists('app')) {
        /**
         * @param string|null $abstract
         * @param array       $parameters
         *
         * @return mixed
         * @throws \Illuminate\Contracts\Container\BindingResolutionException
         */
        function app(?string $abstract = null, array $parameters = []): mixed
        {
            return ContextManager::app($abstract, $parameters);
        }
    }
}

require RIP_PROJECT_PATH . '/vendor/autoload.php';
\define('RIP_OCTANE', InstalledVersions::isInstalled('laravel/octane'));

/*** Initialize the HTTP service */
try {
    if (RIP_OCTANE) {
        $octaneWorker = new Worker(
            new ApplicationFactory(RIP_PROJECT_PATH),
            $octaneClient = new RippleClient()
        );

        $octaneWorker->boot();
        $application = $octaneWorker->application();

        /*** @var Manager $manager */
        $manager = $application->make(Manager::class);
        $manager->add($httpWorker = new HttpWorker($application, RIP_HTTP_LISTEN, RIP_HTTP_WORKERS, RIP_WATCH));
        RIP_OCTANE && $httpWorker->customHandler(static function (Request $rippleHttpRequest) use ($octaneClient, $octaneWorker) {
            $octaneWorker->handle(
                ...$octaneClient->marshalRequest(new RequestContext(['rippleHttpRequest' => $rippleHttpRequest]))
            );
        });
    } else {
        $application = Factory::createApplication();
        $kernel      = $application->make(Kernel::class);
        $kernel->bootstrap();
        $application->loadDeferredProviders();

        /*** @var Manager $manager */
        $manager = $application->make(Manager::class);
        $manager->add($httpWorker = new HttpWorker($application, RIP_HTTP_LISTEN, RIP_HTTP_WORKERS, RIP_WATCH));
    }
} catch (BindingResolutionException $e) {
    Output::exception($e);
    exit(1);
}

/*** Register a singleton of Ripple service */
$application->singleton(Manager::class, static fn () => $manager);
$application->singleton(HttpWorker::class, static fn () => $httpWorker);

/*** Hot reload part */
$projectChannel           = channel(RIP_PROJECT_PATH);
$includedFiles            = \get_included_files();
$hotReload                = static function (string $file) use ($manager, $includedFiles, $projectChannel) {
    if (!\is_file($file)) {
        return;
    }

    if (\in_array($file, $includedFiles, true)) {
        \__rip_restart($projectChannel);
    } else {
        \__rip_reload($manager);

        $date = \date('Y-m-d H:i:s');
        $file = Util::getRelativePath($file, RIP_PROJECT_PATH);
        Output::writeln("[{$date}] {$file} has been modified");
    }
};

$hotReloadWatch           = Factory::createMonitor();
$hotReloadWatch->onTouch = static fn () => \__rip_restart($projectChannel);
$hotReloadWatch->onRemove = static fn () => \__rip_restart($projectChannel);
$hotReloadWatch->onModify = $hotReload;
if (RIP_WATCH) {
    $hotReloadWatch->run();
}

/*** Guardian part */
async(static function () use ($manager) {
    $channel = channel(RIP_VIRTUAL_ID, true);
    while (1) {
        $control = $channel->receive();
        if ($control === 'reload') {
            \__rip_reload($manager);
        }

        if ($control === 'stop') {
            $manager->terminate();
            exit(0);
        }
    }
});

try {
    onSignal(\SIGTERM, static function () use ($manager) {
        $manager->terminate();
        exit(0);
    });
} catch (UnsupportedFeatureException $e) {
    Output::exception($e);
    exit(1);
}

/*** start */
Output::info("[laravel-ripple]", 'started');
$manager->run();
repeat(static function () {
    \gc_collect_cycles();
    \Co\sleep(1);
}, 1);
wait();
