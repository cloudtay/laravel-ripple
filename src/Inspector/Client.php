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
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Channel\Channel;
use Ripple\Coroutine\Context;
use Ripple\File\Lock;
use Ripple\Process\Runtime;
use Ripple\Utils\Output;
use RuntimeException;
use Throwable;

use function base_path;
use function cli_set_process_title;
use function Co\channel;
use function Co\go;
use function Co\onSignal;
use function Co\process;
use function Co\repeat;
use function Co\wait;
use function config_path;
use function file_exists;
use function gc_collect_cycles;
use function pcntl_exec;
use function shell_exec;
use function sprintf;
use function storage_path;

use const PHP_BINARY;
use const SIGINT;
use const SIGQUIT;
use const SIGTERM;

class Client
{
    /*** @var Inspector */
    public readonly Inspector $inspector;

    /*** @var Channel */
    public readonly Channel $channel;

    /*** @var Lock */
    public readonly Lock $lock;

    /*** @var bool */
    public bool $owner = false;

    /**
     *
     */
    public function __construct()
    {
        $this->lock = \Co\lock(base_path());
        $this->inspector = new Inspector($this);
    }

    /**
     * @param bool $daemon
     *
     * @return void
     * @throws UnsupportedFeatureException
     */
    public function start(bool $daemon = false): void
    {
        if (!file_exists(config_path('ripple.php'))) {
            Output::warning('Please execute the following command to publish the configuration files first.');
            Output::writeln('php artisan vendor:publish --tag=ripple-config');
            return;
        }

        if ($this->serverIsRunning()) {
            return;
        }

        if ($daemon) {
            $this->lock->unlock();
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
        if (!$this->lock->exclusion(false)) {
            throw new UnsupportedFeatureException('the service is started');
        }

        $this->channel = channel(base_path(), true);

        $this->monitor();
        $this->startProcess();
        wait();
    }

    /**
     * @var Runtime
     */
    protected Runtime $runtime;

    /**
     * @var Context
     */
    protected Context $guardCoroutine;

    /**
     * @return void
     */
    protected function startProcess(): void
    {
        if (isset($this->guardCoroutine)) {
            $this->guardCoroutine->terminate();
        }

        if (isset($this->runtime)) {
            $this->runtime->terminate();
        }

        $runtime = process(function () {
            $envs = [
                'RIP_PROJECT_PATH' => base_path(),
                'RIP_HTTP_LISTEN' => Config::get('ripple.HTTP_LISTEN'),
                'RIP_HTTP_WORKERS' => Config::get('ripple.HTTP_WORKERS'),
                'RIP_WATCH' => Config::get('ripple.WATCH'),
                'RIP_HOOK' => InstalledVersions::isInstalled('laravel/octane') ? 0 : 1,
            ];

            pcntl_exec(PHP_BINARY, [sprintf('%s/%s', __DIR__, 'ServerBin.php')], $envs);
        })->run();

        if (!$runtime) {
            throw new RuntimeException('');
        }

        $this->runtime = $runtime;
        $this->guardCoroutine = go(function () use ($runtime) {
            while (1) {
                \Co\sleep(1);
                if (!$this->runtime->isRunning()) {
                    go(fn () => $this->startProcess());
                }
            }
        });
    }

    /**
     * @return void
     */
    private function monitor(): void
    {
        go(function () {
            while (1) {
                $command = $this->channel->receive();
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
                        Output::write($command);
                        break;
                }
            }
        });

        try {
            onSignal(SIGINT, function () {
                $this->stop();
            });

            onSignal(SIGTERM, function () {
                $this->stop();
            });

            onSignal(SIGQUIT, function () {
                $this->stop();
            });
        } catch (UnsupportedFeatureException) {
            Output::warning('Failed to register signal handler');
        }

        cli_set_process_title('ripple-laravel-ware');
        repeat(static function () {
            gc_collect_cycles();
        }, 1);
    }

    /**
     * @return void
     */
    #[NoReturn]
    public function stop(): void
    {
        if (isset($this->guardCoroutine)) {
            $this->guardCoroutine->terminate();
        }

        if (isset($this->runtime)) {
            $this->runtime->terminate();
        }

        exit(0);
    }

    /**
     * @return void
     */
    public function reload(): void
    {
        if (isset($this->runtime)) {
            $this->runtime->terminate();
        }
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function restart(): void
    {
        if (isset($this->runtime)) {
            $this->runtime->terminate();
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

        if ($this->lock->shareable(false)) {
            $this->lock->unlock();
            return false;
        }

        return true;
    }
}
