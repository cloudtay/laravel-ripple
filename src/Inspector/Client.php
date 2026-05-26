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

namespace Laravel\Ripple\Inspector;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Config;
use JetBrains\PhpStorm\NoReturn;
use Ripple\Coroutine;
use Ripple\Event;
use Ripple\Process;
use Ripple\Runtime\Scheduler;
use Ripple\Runtime\Support\Stdin;
use Ripple\Serial\Zx7e;
use Ripple\Stream;
use RuntimeException;
use Throwable;

use function base_path;
use function cli_set_process_title;
use function Co\go;
use function Co\wait;
use function config_path;
use function fclose;
use function file_exists;
use function fopen;
use function fwrite;
use function is_array;
use function json_decode;
use function json_encode;
use function microtime;
use function parse_url;
use function pcntl_exec;
use function shell_exec;
use function sprintf;
use function storage_path;
use function flock;
use function unlink;
use function posix_mkfifo;
use function stream_socket_client;
use function stream_set_blocking;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const SIGINT;
use const SIGKILL;
use const SIGQUIT;
use const SIGTERM;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use const LOCK_UN;
use const PHP_URL_HOST;
use const PHP_URL_PORT;

class Client
{
    public const COMMAND_TIMEOUT_SECONDS = 15;
    private const STOP_TIMEOUT_SECONDS = 5;
    private const READY_TIMEOUT_SECONDS = 5;
    private const PORT_RELEASE_TIMEOUT_SECONDS = 5;

    /**
     * @var Inspector
     */
    public readonly Inspector $inspector;

    /**
     * @var bool
     */
    public bool $owner = false;

    /**
     * @var mixed
     */
    private mixed $lock;

    /**
     * @var Stream
     */
    private Stream $channel;

    /**
     * @var string
     */
    public readonly string $channelPath;

    /**
     *
     */
    public function __construct()
    {
        $this->lock = fopen(__FILE__, 'c');
        $this->inspector = new Inspector($this);
        $this->channelPath = storage_path('ripple-channel.fifo');
    }

    /**
     * @param bool $daemon
     * @return void
     * @throws RuntimeException
     */
    public function start(bool $daemon = false): void
    {
        if (!file_exists(config_path('ripple.php'))) {
            Stdin::println('Please execute the following command to publish the configuration files first.');
            Stdin::println('php artisan vendor:publish --tag=ripple-config');
            return;
        }

        if ($this->serverIsRunning()) {
            return;
        }

        if ($daemon) {
            // 释放锁并在子进程中后台运行
            flock($this->lock, LOCK_UN);
            $command = sprintf(
                '%s %s ripple:server start > %s &',
                PHP_BINARY,
                base_path('artisan'),
                storage_path('logs/ripple.log')
            );
            shell_exec($command);
            return;
        }

        $this->owner = true;
        if (!flock($this->lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('the service is started');
        }

        $this->monitor();
        $this->startProcess();
        wait();
    }

    /**
     * @var int|null
     */
    protected ?int $warePid = null;

    /**
     * @var Coroutine
     */
    protected Coroutine $guard;

    /**
     * @var bool
     */
    private bool $intentionalLifecycleChange = false;

    /**
     * @return void
     */
    protected function startProcess(): void
    {
        $this->warePid = Process::fork(function () {
            $envs = [
                'RIP_PROJECT_PATH' => base_path(),
                'RIP_HTTP_LISTEN' => Config::get('ripple.HTTP_LISTEN'),
                'RIP_HTTP_WORKERS' => Config::get('ripple.HTTP_WORKERS'),
                'RIP_WATCH' => Config::get('ripple.WATCH'),
                'RIP_HOOK' => InstalledVersions::isInstalled('laravel/octane') ? 0 : 1,
            ];

            pcntl_exec(PHP_BINARY, [sprintf('%s/%s', __DIR__, 'ServerBin.php')], $envs);
        });

        Stdin::println("starting pid={$this->warePid}");

        $this->guard = go(function () {
            $pid = $this->warePid;
            $exitCode = Process::wait($pid);
            if ($this->intentionalLifecycleChange || $this->warePid !== $pid) {
                return;
            }

            Stdin::println("worker exited pid={$pid} code={$exitCode}; restarting");
            $this->waitUntilPortIsReleased();
            $this->startProcess();
            $this->waitUntilReady();
        });
    }

    /**
     * @return void
     */
    private function monitor(): void
    {
        if (file_exists($this->channelPath)) {
            unlink($this->channelPath);
        }

        posix_mkfifo($this->channelPath, 0755);
        $fifo = fopen($this->channelPath, 'r+');
        stream_set_blocking($fifo, false);

        $this->channel = new Stream($fifo);
        go(function () {
            $zx7e = new Zx7e();
            while (1) {
                $read = $this->channel->read(1024);
                $commands = $zx7e->fill($read);
                foreach ($commands as $command) {
                    $command = $this->decodeCommand($command);
                    $action = $command['command'];

                    try {
                        switch ($action) {
                            case 'stop':
                                $this->stop($command['ack']);
                                break;

                            case 'reload':
                                $this->restart('reload', $command['ack']);
                                break;

                            case 'restart':
                                $this->restart('restart', $command['ack']);
                                break;

                            default:
                                Stdin::print($action);
                                break;
                        }
                    } catch (Throwable $throwable) {
                        $this->ack($command['ack'], false, $throwable->getMessage());
                    }
                }
                \Co\sleep(1);
            }
        });

        try {
            Event::watchSignal(SIGINT, fn () => $this->stop());
            Event::watchSignal(SIGTERM, fn () => $this->stop());
            Event::watchSignal(SIGQUIT, fn () => $this->stop());
        } catch (RuntimeException) {
            Stdin::println('Failed to register signal handler');
        }

        cli_set_process_title('ripple-laravel-ware');
    }

    /**
     * @return void
     */
    #[NoReturn]
    public function stop(?string $ackPath = null): void
    {
        if (isset($this->guard)) {
            Scheduler::terminate($this->guard)->unwrap();
        }

        $this->stopOldProcess('stop');
        $this->ack($ackPath, true, 'stop complete');

        if (isset($this->channel)) {
            $this->channel->close();
        }

        if (file_exists($this->channelPath)) {
            unlink($this->channelPath);
        }

        exit(0);
    }

    /**
     * @return void
     */
    public function reload(): void
    {
        $this->restart('reload');
    }

    /**
     * @param string $reason
     * @param string|null $ackPath
     * @return void
     * @throws Throwable
     */
    public function restart(string $reason = 'restart', ?string $ackPath = null): void
    {
        (new ServerLifecycle(
            static function (string $message): void {
                Stdin::println($message);
            },
            function () use ($reason): void {
                $this->stopOldProcess($reason);
            },
            function (): void {
                $this->waitUntilPortIsReleased();
            },
            function (): void {
                $this->startProcess();
            },
            function (): void {
                $this->waitUntilReady();
            },
        ))->restart($reason);

        $this->ack($ackPath, true, "{$reason} complete");
    }

    /**
     * @return bool
     */
    public function serverIsRunning(): bool
    {
        if ($this->owner) {
            throw new RuntimeException('the service is running');
        }

        // 获取到锁则说明服务没在运行
        if (flock($this->lock, LOCK_SH | LOCK_NB)) {
            flock($this->lock, LOCK_UN);
            return false;
        }

        return true;
    }

    /**
     * @param string $payload
     * @return array{command:string,ack:string|null}
     */
    private function decodeCommand(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = null;
        }

        if (is_array($decoded) && isset($decoded['command'])) {
            return [
                'command' => (string) $decoded['command'],
                'ack' => isset($decoded['ack']) ? (string) $decoded['ack'] : null,
            ];
        }

        return [
            'command' => $payload,
            'ack' => null,
        ];
    }

    /**
     * @param string $reason
     * @return void
     */
    private function stopOldProcess(string $reason): void
    {
        if (!$this->warePid) {
            return;
        }

        $pid = $this->warePid;
        Stdin::println("stopping pid={$pid} reason={$reason}");

        $this->intentionalLifecycleChange = true;
        if (isset($this->guard)) {
            Scheduler::terminate($this->guard)->unwrap();
        }

        if (!Process::signal($pid, SIGTERM)) {
            throw new RuntimeException("Failed to send SIGTERM to pid={$pid}.");
        }

        $exitCode = $this->waitForProcessExit($pid, self::STOP_TIMEOUT_SECONDS);
        if ($exitCode === null) {
            Stdin::println("pid={$pid} did not exit within " . self::STOP_TIMEOUT_SECONDS . 's; sending SIGKILL');
            Process::signal($pid, SIGKILL);
            $exitCode = $this->waitForProcessExit($pid, 1);
        }

        if ($exitCode === null) {
            throw new RuntimeException("Timed out waiting for old process pid={$pid} to exit.");
        }

        $this->warePid = null;
        $this->intentionalLifecycleChange = false;
        Stdin::println("old process exited pid={$pid} code={$exitCode}");
    }

    /**
     * @param int $pid
     * @param int $timeout
     * @return int|null
     */
    private function waitForProcessExit(int $pid, int $timeout): ?int
    {
        $exited = false;
        $exitCode = null;

        go(function () use ($pid, &$exited, &$exitCode): void {
            $exitCode = Process::wait($pid);
            $exited = true;
        });

        $deadline = microtime(true) + $timeout;
        while (!$exited && microtime(true) < $deadline) {
            \Co\sleep(0.05);
        }

        return $exited ? $exitCode : null;
    }

    /**
     * @return void
     */
    private function waitUntilPortIsReleased(): void
    {
        $target = $this->listenTarget();
        if ($target === null) {
            return;
        }

        $deadline = microtime(true) + self::PORT_RELEASE_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            if (!$this->canConnect($target)) {
                Stdin::println("port released {$target}");
                return;
            }
            \Co\sleep(0.05);
        }

        throw new RuntimeException("Timed out waiting for {$target} to be released.");
    }

    /**
     * @return void
     */
    private function waitUntilReady(): void
    {
        $target = $this->listenTarget();
        if ($target === null) {
            Stdin::println('ready check skipped: unsupported RIP_HTTP_LISTEN value');
            return;
        }

        $deadline = microtime(true) + self::READY_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            if ($this->canConnect($target)) {
                Stdin::println("listening on {$target}");
                return;
            }
            \Co\sleep(0.05);
        }

        throw new RuntimeException("Timed out waiting for {$target} to accept connections.");
    }

    /**
     * @return string|null
     */
    private function listenTarget(): ?string
    {
        $listen = (string) Config::get('ripple.HTTP_LISTEN');
        $host = parse_url($listen, PHP_URL_HOST);
        $port = parse_url($listen, PHP_URL_PORT);

        if (!$host || !$port) {
            return null;
        }

        return "tcp://{$host}:{$port}";
    }

    /**
     * @param string $target
     * @return bool
     */
    private function canConnect(string $target): bool
    {
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client($target, $errno, $error, 0.2);
        if ($socket === false) {
            return false;
        }

        fclose($socket);
        return true;
    }

    /**
     * @param string|null $ackPath
     * @param bool        $ok
     * @param string      $message
     * @return void
     */
    private function ack(?string $ackPath, bool $ok, string $message): void
    {
        if (!$ackPath) {
            return;
        }

        $ack = fopen($ackPath, 'w');
        if ($ack === false) {
            Stdin::println("failed to open ack fifo {$ackPath}");
            return;
        }

        fwrite($ack, json_encode([
            'ok' => $ok,
            'message' => $message,
        ], JSON_THROW_ON_ERROR));
        fclose($ack);
    }
}
