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

namespace Laravel\Ripple\Built;

use Laravel\Ripple\Inspector\Client;
use Ripple\Utils\Output;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @Author cclilshy
 * @Date   2024/8/17 16:16
 */
class Command extends \Illuminate\Console\Command
{
    /**
     * the name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ripple:server
    {action=start : the action to perform ,Support start|stop|reload|restart|status}
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
     * @param \Laravel\Ripple\Inspector\Client $client
     *
     * @return void
     */
    public function handle(Client $client): void
    {
        if (!$client->isInstalled()) {
            Output::warning('Please execute the following command to publish the configuration files first.');
            Output::writeln('php artisan vendor:publish --tag=ripple-config');
            return;
        }

        switch ($this->argument('action')) {
            case 'start':
                if ($client->inspector->serverIsRunning()) {
                    Output::warning('the server is already running');
                    return;
                }
                $client->start($this->option('daemon'));
                Output::writeln('server started');
                break;

            case 'stop':
                if (!$client->inspector->serverIsRunning()) {
                    Output::warning('the server is not running');
                    return;
                }
                $client->inspector->stopServer();
                break;

            case 'reload':
                if (!$client->inspector->serverIsRunning()) {
                    Output::warning('the server is not running');
                    return;
                }
                $client->inspector->reloadServer();
                break;

            case 'restart':
                if (!$client->inspector->serverIsRunning()) {
                    Output::warning('the server is not running');
                    return;
                }
                $client->inspector->restartServer();
                break;

            case 'status':
                if (!$client->inspector->serverIsRunning()) {
                    Output::writeln('the server is not running');
                } else {
                    Output::info('the server is running');
                }
                break;

            default:
                Output::warning('Unsupported operation');
        }
    }
}
