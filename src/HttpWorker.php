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

namespace Ripple\Driver\Laravel;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use JetBrains\PhpStorm\NoReturn;
use Ripple\Driver\Laravel\Coroutine\ContextManager;
use Ripple\Driver\Laravel\Events\RequestHandled;
use Ripple\Driver\Laravel\Events\RequestReceived;
use Ripple\Driver\Laravel\Events\RequestTerminated;
use Ripple\Driver\Laravel\Events\WorkerErrorOccurred;
use Ripple\Driver\Laravel\Response\IteratorResponse;
use Ripple\Driver\Laravel\Traits\DispatchesEvents;
use Ripple\Driver\Laravel\Utils\Config;
use Ripple\Driver\Laravel\Utils\Console;
use Ripple\File\File;
use Ripple\File\Monitor;
use Ripple\Http\Server;
use Ripple\Http\Server\Request;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Output;
use Ripple\Worker\Manager;
use Ripple\Worker\Worker;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

use function cli_set_process_title;
use function Co\channel;
use function Co\lock;
use function define;
use function file_exists;
use function fopen;
use function fwrite;
use function getenv;
use function intval;
use function is_dir;
use function is_file;
use function realpath;

use const PHP_EOL;
use const STDOUT;

cli_set_process_title('laravel-guard');

/**
 * @param string $message
 *
 * @return void
 */
#[NoReturn]
function _rip_error_terminate(string $message = ''): void
{
    if ($message) {
        fwrite(STDOUT, $message . PHP_EOL);
    }
    exit(1);
}

$env         = getenv();
$projectPath = $env['RIP_PROJECT_PATH'] ?? null;
$projectPath || _rip_error_terminate("the RIP_PROJECT_PATH environment variable is not set\n");
is_dir($projectPath) || _rip_error_terminate("the RIP_PROJECT_PATH environment variable is not a valid directory\n");
is_file("{$projectPath}/vendor/autoload.php") || _rip_error_terminate("the vendor/autoload.php file was not found in the project directory\n");

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
    return app('rippleHttpWorker');
}

define("RIP_PROJECT_PATH", realpath($projectPath));
require RIP_PROJECT_PATH . '/vendor/autoload.php';

/**
 * @Author cclilshy
 * @Date   2024/8/16 23:38
 */
class HttpWorker extends Worker
{
    use Console;
    use DispatchesEvents;

    /*** @var Server */
    protected Server $server;

    /*** @var Application */
    protected Application $application;

    /**
     * @param string $address
     * @param int    $count
     * @param bool   $reload
     */
    public function __construct(
        protected readonly string $address,
        protected int             $count,
        protected readonly bool   $reload = false
    )
    {
        $this->name = 'http-server';
    }

    /**
     * @return \Illuminate\Foundation\Application
     */
    public static function createApplication(): Application
    {
        return Application::configure(basePath: RIP_PROJECT_PATH)
            ->withRouting()
            ->withMiddleware()
            ->withExceptions()
            ->create();
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/16 23:34
     *
     * @param Manager $manager
     *
     * @return void
     * @throws Throwable
     */
    public function register(Manager $manager): void
    {
        /*** output worker*/
        fwrite(STDOUT, $this->formatRow(['Worker', $this->getName()]));

        /*** output env*/
        fwrite(STDOUT, $this->formatRow(["Conf"]));
        fwrite(STDOUT, $this->formatRow(["- Listen", $this->address]));
        fwrite(STDOUT, $this->formatRow(["- Workers", $this->count]));
        fwrite(STDOUT, $this->formatRow(["- Reload", Config::value2string($this->reload, 'bool')]));

        /*** output logs*/
        fwrite(STDOUT, $this->formatRow(["Logs"]));

        /*** initialize*/
        $this->server = new Server($this->address, ['socket' => ['so_reuseport' => 1, 'so_reuseaddr' => 1]]);
        if ($this->reload) {
            $monitor = File::getInstance()->monitor();
            $monitor->add(RIP_PROJECT_PATH . ('/app'));
            $monitor->add(RIP_PROJECT_PATH . ('/bootstrap'));
            $monitor->add(RIP_PROJECT_PATH . ('/config'));
            $monitor->add(RIP_PROJECT_PATH . ('/routes'));
            $monitor->add(RIP_PROJECT_PATH . ('/resources'));
            if (file_exists(RIP_PROJECT_PATH . ('/.env'))) {
                $monitor->add(RIP_PROJECT_PATH . ('/.env'));
            }

            HttpWorker::relevance($manager, $this, $monitor);
        }
    }

    /**
     * @param \Ripple\Worker\Manager $manager
     * @param \Ripple\Worker\Worker  $worker
     * @param \Ripple\File\Monitor   $monitor
     *
     * @return void
     */
    protected static function relevance(
        Manager $manager,
        Worker  $worker,
        Monitor $monitor
    ): void
    {
        $monitor->onTouch = function (string $file) use ($manager, $worker) {
            $manager->reload($worker->getName());
            Output::writeln("File {$file} touched");
        };

        $monitor->onModify = function (string $file) use ($manager, $worker) {
            $manager->reload($worker->getName());
            Output::writeln("File {$file} modify");
        };

        $monitor->onRemove = function (string $file) use ($manager, $worker) {
            $manager->reload($worker->getName());
            Output::writeln("File {$file} remove");
        };

        $monitor->run();
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 11:08
     * @return void
     */
    public function boot(): void
    {
        cli_set_process_title('laravel-worker');
        $this->application = HttpWorker::createWebApplication();
        $this->application->bind('rippleHttpWorker', fn () => $this);
        $this->application->bind('httpWorker', fn () => $this);
        $this->application->bind(HttpWorker::class, fn () => $this);

        try {
            $this->application->make(Kernel::class)->bootstrap();
        } catch (BindingResolutionException $e) {
            Output::warning("kernel resolution failed: {$e->getMessage()}");
            exit(1);
        }

        $this->application->loadDeferredProviders();
        foreach (HttpWorker::initializeServices() as $service) {
            try {
                $this->application->bound($service)
                &&
                $this->application->make($service);
            } catch (Throwable $e) {
                Output::warning($e->getMessage());
            }
        }

        $this->server->onRequest(function (Request $request) {
            $laravelRequest = new \Illuminate\Http\Request(
                $request->GET,
                $request->POST,
                [],
                $request->COOKIE,
                $request->FILES,
                $request->SERVER,
                $request->CONTENT,
            );
            $laravelRequest->attributes->set('rippleHttpRequest', $request);

            $application = clone $this->application;
            $this->dispatchEvent($application, new RequestReceived($this->application, $application, $laravelRequest));

            try {
                /*** @var \Illuminate\Foundation\Http\Kernel $kernel */
                $kernel          = $application->make(Kernel::class);
                $laravelResponse = $kernel->handle($laravelRequest);

                /*** handle response*/
                $response = $request->getResponse();
                $response->setStatusCode($laravelResponse->getStatusCode());

                foreach ($laravelResponse->headers->allPreserveCaseWithoutCookies() as $key => $value) {
                    $response->withHeader($key, $value);
                }

                foreach ($laravelResponse->headers->getCookies() as $cookie) {
                    $response->withCookie($cookie->getName(), $cookie->__toString());
                }

                if ($laravelResponse instanceof BinaryFileResponse) {
                    $response->setContent(fopen($laravelResponse->getFile()->getPathname(), 'r+'));
                } elseif ($laravelResponse instanceof IteratorResponse) {
                    $response->setContent($laravelResponse->getIterator());
                } else {
                    $response->setContent($laravelResponse->getContent());
                }

                $response->respond();
                /*** handle response end*/

                $this->dispatchEvent($application, new RequestHandled($this->application, $application, $laravelRequest, $laravelResponse));

                $kernel->terminate($laravelRequest, $laravelResponse);
                $this->dispatchEvent($application, new RequestTerminated($this->application, $application, $laravelRequest, $laravelResponse));
            } catch (Throwable $e) {
                $request->respond($e->getMessage(), [], $e->getCode());
                $this->dispatchEvent($application, new WorkerErrorOccurred($this->application, $application, $e));
            } finally {
                foreach (HttpWorker::defaultServicesToWarm() as $service) {
                    try {
                        $application->forgetInstance($service);
                    } catch (Throwable $e) {
                        Output::warning($e->getMessage());
                    }
                }
                unset($application);
            }
        });

        $this->server->listen();
    }

    /**
     * @return \Illuminate\Foundation\Application
     */
    public static function createWebApplication(): Application
    {
        return Application::configure(basePath: RIP_PROJECT_PATH)
            ->withRouting(
                web: RIP_PROJECT_PATH . '/routes/web.php',
                commands: RIP_PROJECT_PATH . '/routes/console.php',
                health: '/up',
            )
            ->withMiddleware(function (Middleware $middleware) {
            })
            ->withExceptions(function (Exceptions $exceptions) {
                //
            })->create();
    }

    /**
     * @return array
     */
    protected static function initializeServices(): array
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
    protected static function defaultServicesToWarm(): array
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

$lock = lock(RIP_PROJECT_PATH);
$lock->exclusion(false) || _rip_error_terminate("the server is already running\n");

$application = HttpWorker::createApplication();
try {
    $kernel = $application->make(\Illuminate\Foundation\Console\Kernel::class);
    $kernel->bootstrap();
    $application->loadDeferredProviders();
} catch (BindingResolutionException $e) {
    _rip_error_terminate("kernel resolution failed: {$e->getMessage()}\n");
}

try {
    $manager = $application->make(Manager::class);
} catch (BindingResolutionException $e) {
    _rip_error_terminate("manager resolution failed: {$e->getMessage()}\n");
}

$worker = new HttpWorker(
    Config::value2string($env['RIP_HTTP_LISTEN'] ?? 'http://127.0.0.1:8008', 'string'),
    intval(Config::value2string($env['RIP_HTTP_WORKERS'] ?? 4, 'string')),
    Config::value2bool($env['RIP_HTTP_RELOAD'] ?? false),
);

$manager->addWorker($worker);
$manager->run();

/*** Guardian part*/
$channel = channel(RIP_PROJECT_PATH, true);
while (1) {
    $control = $channel->receive();
    if ($control === 'stop') {
        $manager->stop();
        exit(0);
    }

    if ($control === 'reload') {
        try {
            $manager->reload();
        } catch (ConnectionException $e) {
            Output::warning($e->getMessage());
        }
    }
}
