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

use Illuminate\Support\Facades\Config;
use Laravel\Ripple\Virtual\Virtual;
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Channel\Channel;
use Ripple\File\Lock;
use Ripple\Utils\Output;

use function base_path;
use function cli_set_process_title;
use function Co\async;
use function Co\channel;
use function Co\onSignal;
use function Co\wait;
use function config_path;
use function file_exists;
use function shell_exec;
use function sprintf;
use function storage_path;

use const PHP_BINARY;
use const SIGINT;
use const SIGQUIT;
use const SIGTERM;

class Client
{
    /*** @var \Laravel\Ripple\Inspector\Inspector */
    public readonly Inspector $inspector;

    /*** @var \Ripple\Channel\Channel */
    public readonly Channel $channel;

    /*** @var \Ripple\File\Lock */
    public readonly Lock $lock;

    /*** @var \Laravel\Ripple\Virtual\Virtual */
    public Virtual $virtual;

    /*** @var bool */
    public bool $owner = false;

    /**
     *
     */
    public function __construct()
    {
        $this->lock      = \Co\lock(base_path());
        $this->inspector = new Inspector($this);
    }

    /**
     * @return bool
     */
    public function isInstalled(): bool
    {
        return file_exists(config_path('ripple.php'));
    }

    /**
     * @param bool $daemon
     *
     * @return void
     */
    public function start(bool $daemon = false): void
    {
        if ($this->inspector->serverIsRunning()) {
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
        $this->lock->exclusion(false);
        $this->virtual = new Virtual(__DIR__ . '/../Virtual/server.bin.php');
        $this->virtual->launch([
            'RIP_PROJECT_PATH' => base_path(),
            'RIP_VIRTUAL_ID'   => $this->virtual->id,

            'RIP_HTTP_LISTEN'  => Config::get('ripple.HTTP_LISTEN'),
            'RIP_HTTP_WORKERS' => Config::get('ripple.HTTP_WORKERS'),
            'RIP_WATCH'        => Config::get('ripple.WATCH'),
        ]);
        $this->virtual->session->onMessage                 = static fn (string $content) => Output::write($content);
        $this->virtual->session->onErrorMessage = static fn (string $content) => Output::error($content);

        $this->channel = channel(base_path(), true);

        async(function () {
            while (1) {
                $command = $this->channel->receive();
                switch ($command) {
                    case 'stop':
                        $this->virtual->stop();
                        exit(0);
                        break;

                    case 'reload':
                        Output::write("\033c");

                        $oldVirtual = $this->virtual;
                        $virtual = new Virtual(__DIR__ . '/../Virtual/server.bin.php');
                        $virtual->launch([
                            'RIP_PROJECT_PATH' => base_path(),
                            'RIP_VIRTUAL_ID'   => $virtual->id,

                            'RIP_HTTP_LISTEN'  => Config::get('ripple.HTTP_LISTEN'),
                            'RIP_HTTP_WORKERS' => Config::get('ripple.HTTP_WORKERS'),
                            'RIP_WATCH'        => Config::get('ripple.WATCH'),
                        ]);
                        $this->virtual                          = $virtual;
                        $this->virtual->session->onMessage = static fn (string $content) => Output::write($content);
                        $this->virtual->session->onErrorMessage = static fn (string $content) => Output::error($content);
                        $oldVirtual->stop();
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

        cli_set_process_title('laravel-ware');
        wait();
    }

    /**
     * @return bool
     */
    public function stop(): bool
    {
        return $this->inspector->stopServer();
    }

    /**
     * @return void
     */
    public function reload(): void
    {
        $this->inspector->reloadServer();
    }
}
