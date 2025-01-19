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

use Illuminate\Console\Command;
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Channel\Channel;
use Ripple\Driver\Laravel\Virtual\Virtual;
use Ripple\File\File;
use Ripple\File\Lock;
use Ripple\Utils\Output;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function base_path;
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

/**
 * @Author cclilshy
 * @Date   2024/8/17 16:16
 */
class Console extends Command
{
    /**
     * the name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ripple:server
    {action=start : the action to perform ,Support start|stop|reload|status}
    {--d|daemon : Run the server in the background}';

    /**
     * the console command description.
     *
     * @var string
     */
    protected $description = 'start the ripple service';

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void
     */
    public function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
    }

    /**
     * 运行服务
     *
     * @return void
     */
    public function handle(): void
    {
        if (!file_exists(config_path('ripple.php'))) {
            Output::warning('Please execute the following command to publish the configuration files first.');
            Output::writeln('php artisan vendor:publish --tag=ripple-config');
            return;
        }

        $this->lock = \Co\lock(base_path());
        switch ($this->argument('action')) {
            case 'start':
                if (!$this->lock->exclusion(false)) {
                    Output::warning('the server is already running');
                    return;
                }
                $this->start();
                break;

            case 'stop':
                if ($this->lock->exclusion(false)) {
                    Output::warning('the server is not running');
                    return;
                }
                $this->stop();
                break;

            case 'reload':
                if ($this->lock->exclusion(false)) {
                    Output::warning('the server is not running');
                    return;
                }

                $this->reload();
                break;

            case 'status':
                $this->status();
                break;

            default:
                Output::warning('Unsupported operation');
                return;
        }
    }

    /*** @var \Ripple\Driver\Laravel\Virtual\Virtual */
    protected Virtual $virtual;

    /*** @var \Ripple\Channel\Channel */
    protected Channel $channel;

    /*** @var \Ripple\File\Lock */
    protected Lock $lock;

    /**
     * @return void
     */
    protected function start(): void
    {

        if ($this->option('daemon')) {
            $this->lock->unlock();
            $command = sprintf(
                '%s %s ripple:server start > %s &',
                PHP_BINARY,
                base_path('artisan'),
                storage_path('logs/ripple.log')
            );
            shell_exec($command);
            Output::writeln('server started');
            return;
        }

        $this->virtual = new Virtual();
        $this->virtual->launch();
        $this->channel = channel(base_path(), true);
        async(function () {
            while (1) {
                $command = $this->channel->receive();
                switch ($command) {
                    case 'stop':
                        $this->virtual->channel->send('stop');
                        try {
                            \Co\sleep(0.1);
                            if ($this->virtual->session->getStatus('running')) {
                                \Co\sleep(1);
                                $this->virtual->session->inputSignal(SIGINT);
                            }
                        } catch (Throwable) {
                        }
                        exit(0);
                        break;

                    case 'reload':
                        $oldVirtual = $this->virtual;
                        $virtual    = new Virtual();
                        $virtual->launch();
                        $this->virtual = $virtual;

                        $oldVirtual->channel->send('stop');
                        try {
                            \Co\sleep(0.1);
                            if ($oldVirtual->session->getStatus('running')) {
                                \Co\sleep(1);
                                $oldVirtual->session->inputSignal(SIGINT);
                            }
                        } catch (Throwable) {
                        }

                        break;
                }
            }
        });

        $monitor = File::getInstance()->monitor();
        $monitor->add(base_path('/app'));
        $monitor->add(base_path('/bootstrap'));
        $monitor->add(base_path('/config'));
        $monitor->add(base_path('/routes'));
        $monitor->add(base_path('/resources'));
        if (file_exists(base_path('/.env'))) {
            $monitor->add(base_path('/.env'));
        }

        $monitor->onModify = fn () => $this->reload();
        $monitor->onTouch  = fn () => $this->reload();
        $monitor->onRemove = fn () => $this->reload();
        $monitor->run();

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

        wait();
    }

    /**
     * @return void
     */
    protected function stop(): void
    {


        $channel = channel(base_path());
        $channel->send('stop');
        exit(0);
    }

    /**
     * @return void
     */
    protected function reload(): void
    {
        $channel = channel(base_path());
        $channel->send('reload');
        Output::info('the server is reloading');
    }

    /**
     * @return void
     */
    protected function status(): void
    {
        if ($this->lock->exclusion(false)) {
            Output::writeln('the server is not running');
        } else {
            Output::info('the server is running');
        }
    }
}
