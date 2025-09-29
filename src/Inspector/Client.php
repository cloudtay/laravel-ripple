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
use function file_exists;
use function pcntl_exec;
use function shell_exec;
use function sprintf;
use function storage_path;
use function fopen;
use function flock;
use function unlink;
use function posix_mkfifo;
use function stream_set_blocking;

use const PHP_BINARY;
use const SIGINT;
use const SIGQUIT;
use const SIGTERM;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use const LOCK_UN;

class Client
{
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
     * @return void
     */
    protected function startProcess(): void
    {
        if ($this->warePid) {
            Process::signal($this->warePid, SIGTERM);
        }

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

        $this->guard = go(function () {
            Process::wait($this->warePid);
            $this->startProcess();
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
                    switch ($command) {
                        case 'stop':
                            $this->stop();
                            break;

                        case 'reload':
                            $this->reload();
                            break;

                        case 'restart':
                            $this->restart();
                            break;

                        default:
                            Stdin::print($command);
                            break;
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
    public function stop(): void
    {
        if (isset($this->guard)) {
            Scheduler::terminate($this->guard)->resolve();
        }

        if ($this->warePid) {
            Process::signal($this->warePid, SIGTERM);
        }

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
        if ($this->warePid) {
            Process::signal($this->warePid, SIGTERM);
        }
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function restart(): void
    {
        if ($this->warePid) {
            Process::signal($this->warePid, SIGTERM);
        }
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
}
