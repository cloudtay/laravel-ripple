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
use Ripple\File\File;
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
use function file_put_contents;
use function gc_collect_cycles;
use function putenv;
use function shell_exec;
use function sprintf;
use function storage_path;
use function strlen;

use const PHP_BINARY;
use const SEEK_CUR;
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
        $this->binChannel = channel(base_path('bin'), true);

        $this->monitor();
        $this->restartProcess();
        wait();
    }

    /**
     * @var Runtime
     */
    protected Runtime $runtime;

    /**
     * @var Channel
     */
    protected Channel $binChannel;

    /**
     * @var Context
     */
    protected Context $guardCoroutine;

    /**
     * @return void
     */
    protected function restartProcess(): void
    {
        if (isset($this->guardCoroutine)) {
            $this->guardCoroutine->terminate();
        }

        if (isset($this->runtime)) {
            try {
                $this->runtime->await();
            } catch (Throwable $e) {
                throw new RuntimeException('the service cannot be stopped');
            }
        }

        $logPath = storage_path('/logs/ripple-running.log');
        file_put_contents($logPath, '');

        $runtime = process(function () use ($logPath) {
            $envs = [
                'RIP_PROJECT_PATH' => base_path(),
                'RIP_HTTP_LISTEN' => Config::get('ripple.HTTP_LISTEN'),
                'RIP_HTTP_WORKERS' => Config::get('ripple.HTTP_WORKERS'),
                'RIP_WATCH' => Config::get('ripple.WATCH'),
                'RIP_HOOK' => InstalledVersions::isInstalled('laravel/octane') ? 0 : 1,
                'RIP_SHELL_PROCESS_NAME' => Config::get('ripple.PROCESS_NAMES.SERVER_BIN', ''),
            ];

            foreach ($envs as $name => $env) {
                putenv("{$name}={$env}");
            }

            $command = sprintf(
                '%s %s/ServerBin.php >> %s',
                PHP_BINARY,
                __DIR__,
                $logPath
            );
            shell_exec($command);
        })->run();

        if (!$runtime) {
            throw new RuntimeException('');
        }

        $this->runtime = $runtime;
        $this->guardCoroutine = go(function () use ($logPath, $runtime) {
            $logStream = File::open($logPath, 'r');
            while (1) {
                $output = $logStream->read(1024);
                $logStream->seek(strlen($output), SEEK_CUR);
                Output::write($output);
                \Co\sleep(1);

                if (!$this->runtime->isRunning()) {
                    go(fn () => $this->restartProcess());
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
        } catch (UnsupportedFeatureException $e) {
            Output::warning('Failed to register signal handler');
        }

        cli_set_process_title(Config::get('ripple.PROCESS_NAMES.MONITOR', 'laravel-ware'));
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

        $this->binChannel->send('stop');
        exit(0);
    }

    /**
     * @return void
     */
    public function reload(): void
    {
        $this->binChannel->send('reload');
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function restart(): void
    {
        if (isset($this->runtime)) {
            $this->binChannel->send('stop');
            $this->restartProcess();
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
