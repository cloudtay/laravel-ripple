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

namespace Laravel\Ripple;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Laravel\Ripple\Built\Events\RequestHandled;
use Laravel\Ripple\Built\Events\RequestReceived;
use Laravel\Ripple\Built\Events\RequestTerminated;
use Laravel\Ripple\Built\Events\WorkerErrorOccurred;
use Laravel\Ripple\Built\Factory;
use Laravel\Ripple\Built\Traits\DispatchesEvents;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;
use Ripple\Net\Http;
use Ripple\Net\Http\Response;
use Ripple\Net\Http\Server;
use Ripple\Runtime\Support\Stdin;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Worker;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

use function cli_set_process_title;
use function is_array;
use function is_object;
use function stream_context_create;

/**
 * @Author cclilshy
 * @Date   2024/8/16 23:38
 */
class HttpWorker extends Worker
{
    use DispatchesEvents;

    /*** @var Server */
    protected Server $server;

    /*** @var Closure */
    protected Closure $customHandler;

    /**
     * @param Application $application
     * @param string $address
     * @param int $count
     * @param bool $reload
     */
    public function __construct(
        protected Application $application,
        protected string      $address,
        public int            $count,
        protected bool        $reload = false,
    ) {
        $this->name = 'http-server';
        parent::__construct();
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/16 23:34
     * @return void
     */
    public function register(): void
    {
        /*** output worker*/
        Stdin::println("worker listening on {$this->address} x {$this->count} registered");

        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1
            ]
        ]);

        /*** initialize*/
        $this->server = Http::server($this->address, $context);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 11:08
     * @return void
     */
    public function boot(): void
    {
        cli_set_process_title('ripple-laravel-worker.http');
        $this->application->singleton('rippleHttpWorker', fn () => $this);
        $this->application->singleton('httpWorker', fn () => $this);
        $this->application->singleton(HttpWorker::class, fn () => $this);

        try {
            $this->application->make(Kernel::class)->bootstrap();
        } catch (BindingResolutionException $e) {
            Stdin::println("kernel resolution failed: {$e->getMessage()}");
            exit(1);
        }

        $this->application->loadDeferredProviders();
        foreach (Factory::initializeServices() as $service) {
            try {
                $this->application->bound($service)
                &&
                $this->application->make($service);
            } catch (Throwable $e) {
                Stdin::println($e->getMessage());
            }
        }

        $this->server->onRequest = fn (ServerRequestInterface $request) => $this->onRequest($request);
        $this->server->listen();
    }

    /**
     * @param ServerRequestInterface $request
     * @return ResponseInterface|null
     * @throws ReflectionException
     */
    protected function onRequest(ServerRequestInterface $request): ?ResponseInterface
    {
        if (isset($this->customHandler)) {
            $response = ($this->customHandler)($request);
            return $response instanceof ResponseInterface ? Util::fromPsrResponse($response) : new Response(500);
        }

        $application = clone $this->application;
        $laravelRequest = $this->toLaravelRequest($request);

        $laravelRequest->attributes->set('rippleHttpRequest', $request);
        $laravelRequest->attributes->set('httpRequest', $request);
        $application->bind('request', fn () => $laravelRequest);
        $application->bind(\Illuminate\Http\Request::class, fn () => $laravelRequest);
        $this->dispatchEvent($application, new RequestReceived($this->application, $application, $laravelRequest));

        try {
            /*** @var \Illuminate\Foundation\Http\Kernel $kernel */
            $kernel = $application->make(Kernel::class);
            $laravelResponse = $kernel->handle($laravelRequest);

            $response = Util::toRippleResponse($laravelResponse);

            $this->dispatchEvent($application, new RequestHandled($this->application, $application, $laravelRequest, $laravelResponse));

            try {
                $kernel->terminate($laravelRequest, $laravelResponse);
                $this->dispatchEvent($application, new RequestTerminated($this->application, $application, $laravelRequest, $laravelResponse));
            } catch (Throwable $e) {
                $this->dispatchEvent($application, new WorkerErrorOccurred($this->application, $application, $e));
            }

            return $response;
        } catch (ConnectionException $e) {
            $this->dispatchEvent($application, new WorkerErrorOccurred($this->application, $application, $e));
            return null;
        } catch (Throwable $e) {
            $this->dispatchEvent($application, new WorkerErrorOccurred($this->application, $application, $e));
            return new Response(500, ['Content-Type' => 'text/plain'], $e->getMessage());
        } finally {
            foreach (Factory::defaultServicesToWarm() as $service) {
                try {
                    $application->forgetInstance($service);
                } catch (Throwable $e) {
                    Stdin::println($e->getMessage());
                }
            }
            unset($application);
        }
    }

    protected function toLaravelRequest(ServerRequestInterface $request): \Illuminate\Http\Request
    {
        $parsedBody = $request->getParsedBody();

        return new \Illuminate\Http\Request(
            $request->getQueryParams(),
            is_array($parsedBody) ? $parsedBody : (is_object($parsedBody) ? (array)$parsedBody : []),
            [],
            $request->getCookieParams(),
            $this->parseFiles($request->getUploadedFiles()),
            $request->getServerParams(),
            (string)$request->getBody(),
        );
    }

    /*** @return array<string, array<UploadedFile>> */
    protected function parseFiles(array $files): array
    {
        return Util::normalizeRippleFiles($files);
    }

    /**
     * @param Closure $handler
     * @return void
     */
    public function customHandler(Closure $handler): void
    {
        $this->customHandler = $handler;
    }
}
