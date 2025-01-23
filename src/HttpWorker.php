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
use Illuminate\Support\Facades\Config;
use Ripple\Driver\Laravel\Events\RequestHandled;
use Ripple\Driver\Laravel\Events\RequestReceived;
use Ripple\Driver\Laravel\Events\RequestTerminated;
use Ripple\Driver\Laravel\Events\WorkerErrorOccurred;
use Ripple\Driver\Laravel\Response\IteratorResponse;
use Ripple\Driver\Laravel\Traits\DispatchesEvents;
use Ripple\Http\Server;
use Ripple\Http\Server\Request;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Output;
use Ripple\Worker\Manager;
use Ripple\Worker\Worker;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

use function boolval;
use function cli_set_process_title;
use function fopen;
use function intval;

/**
 * @Author cclilshy
 * @Date   2024/8/16 23:38
 */
class HttpWorker extends Worker
{
    use DispatchesEvents;

    /*** @var Server */
    protected Server $server;

    /*** @var string */
    protected string $address;

    /*** @var int */
    protected int $count;

    /*** @var bool */
    protected bool $reload = false;

    /**
     * @param \Illuminate\Foundation\Application $application
     */
    public function __construct(protected Application $application)
    {
        $this->name    = 'http-server';
        $this->address = Config::get('ripple.HTTP_LISTEN', 'http://127.0.0.1:8008');
        $this->count = intval(Config::get('ripple.HTTP_WORKERS', 1));
        $this->reload = boolval(Config::get('ripple.HTTP_RELOAD', false));
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
        Output::info("worker listening on {$this->address} x {$this->count} registered");

        /*** initialize*/
        $this->server = new Server($this->address, ['socket' => ['so_reuseport' => 1, 'so_reuseaddr' => 1]]);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 11:08
     * @return void
     */
    public function boot(): void
    {
        cli_set_process_title('laravel-worker.http');
        $this->application->singleton('rippleHttpWorker', fn () => $this);
        $this->application->singleton('httpWorker', fn () => $this);
        $this->application->singleton(HttpWorker::class, fn () => $this);

        try {
            $this->application->make(Kernel::class)->bootstrap();
        } catch (BindingResolutionException $e) {
            Output::warning("kernel resolution failed: {$e->getMessage()}");
            exit(1);
        }

        $this->application->loadDeferredProviders();
        foreach (Factory::initializeServices() as $service) {
            try {
                $this->application->bound($service)
                &&
                $this->application->make($service);
            } catch (Throwable $e) {
                Output::warning($e->getMessage());
            }
        }

        $this->server->onRequest(fn (Request $request) => $this->onRequest($request));
        $this->server->listen();
    }

    /**
     * @param \Ripple\Http\Server\Request $request
     *
     * @return void
     */
    protected function onRequest(Request $request): void
    {
        $application    = clone $this->application;
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
        $laravelRequest->attributes->set('httpRequest', $request);
        $application->bind('request', fn () => $laravelRequest);
        $application->bind(\Illuminate\Http\Request::class, fn () => $laravelRequest);
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
        } catch (ConnectionException $e) {
            $this->dispatchEvent($application, new WorkerErrorOccurred($this->application, $application, $e));
        } catch (Throwable $e) {
            $request->respond($e->getMessage(), [], $e->getCode());
            $this->dispatchEvent($application, new WorkerErrorOccurred($this->application, $application, $e));
        } finally {
            foreach (Factory::defaultServicesToWarm() as $service) {
                try {
                    $application->forgetInstance($service);
                } catch (Throwable $e) {
                    Output::warning($e->getMessage());
                }
            }
            unset($application);
        }
    }
}
