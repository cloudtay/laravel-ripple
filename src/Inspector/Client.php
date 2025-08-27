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
use function Co\repeat;
use function Co\wait;
use function config_path;
use function file_exists;
use function shell_exec;
use function sprintf;
use function storage_path;
use function gc_collect_cycles;

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

    /*** @var Virtual */
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
        $this->channel = channel(base_path(), true);
        $this->virtual = $this->launchVirtual();
        $this->monitor();
    }

    /**
     * @return Virtual
     */
    private function launchVirtual(): Virtual
    {
        $virtual = new Virtual(__DIR__ . '/../Virtual/server.bin.php');
        $virtual->launch([
            'RIP_PROJECT_PATH' => base_path(),
            'RIP_VIRTUAL_ID'   => $virtual->id,

            'RIP_HTTP_LISTEN'  => Config::get('ripple.HTTP_LISTEN'),
            'RIP_HTTP_WORKERS' => Config::get('ripple.HTTP_WORKERS'),
            'RIP_WATCH'        => Config::get('ripple.WATCH'),
            'RIP_HOOK'         => InstalledVersions::isInstalled('laravel/octane') ? 0 : 1,
        ]);
        $virtual->session->onMessage      = static fn (string $content) => Output::write($content);
        $virtual->session->onErrorMessage = static fn (string $content) => Output::error($content);
        return $virtual;
    }

    /**
     * @return void
     */
    private function monitor(): void
    {
        async(function () {
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

        cli_set_process_title('laravel-ware');
        repeat(static function () {
            gc_collect_cycles();
        }, 1);
        wait();
    }

    /**
     * @return void
     */
    #[NoReturn] public function stop(): void
    {
        if (!isset($this->virtual)) {
            return;
        }

        $this->virtual->channel->send('stop');
        $this->virtual->stop();
        exit(0);
    }

    /**
     * @return void
     */
    public function reload(): void
    {
        if (!isset($this->virtual)) {
            return;
        }

        $this->virtual->channel->send('reload');
    }

    /**
     * @return void
     */
    public function restart(): void
    {
        if (!isset($this->virtual)) {
            return;
        }

        Output::write("\033c");
        $oldVirtual    = $this->virtual;
        $this->virtual = $this->launchVirtual();
        $oldVirtual->stop();
    }
}
