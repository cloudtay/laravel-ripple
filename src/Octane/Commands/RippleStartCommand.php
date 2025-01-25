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

namespace Laravel\Ripple\Octane\Commands;

use Laravel\Octane\Commands\Command;
use Laravel\Octane\Commands\Concerns\InteractsWithEnvironmentVariables;
use Laravel\Octane\Commands\Concerns\InteractsWithServers;
use Laravel\Ripple\Octane\RippleServerProcessInspector;
use Ripple\Utils\Output;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Process\Process;

use function app;
use function base_path;
use function realpath;

use const PHP_BINARY;

class RippleStartCommand extends Command implements SignalableCommandInterface
{
    use InteractsWithEnvironmentVariables;
    use InteractsWithServers;

    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'octane:ripple
                    {--host= : The IP address the server should bind to}
                    {--port= : The port the server should be available on}
                    {--workers=auto : The number of workers that should be available to handle requests}
                    {--task-workers=auto : The number of task workers that should be available to handle tasks}
                    {--max-requests=500 : The number of requests to process before reloading the server}
                    {--watch : Automatically reload the server when the application is modified}
                    {--poll : Use file system polling while watching in order to watch files over a network}';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Start the Octane Ripple server';

    /**
     * Indicates whether the command should be shown in the Artisan command list.
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * @param \Laravel\Ripple\Octane\RippleServerProcessInspector $inspector
     *
     * @return int
     */
    public function handle(RippleServerProcessInspector $inspector): int
    {
        $this->ensurePortIsAvailable();
        if ($inspector->serverIsRunning()) {
            return 1;
        }

        if ($watch = $this->option('watch')) {
            $this->input->setOption('watch', false);
        }

        $workers = $this->option('workers') ?? 'auto';
        if (!$workers || $workers === 'auto') {
            $workers = 1;
        }

        $binPath = realpath(__DIR__ . '/../Bin');
        $process = new Process(
            command: [PHP_BINARY, './ripple-ware.bin.php'],
            cwd: $binPath,
            env: [
                'RIP_PROJECT_PATH'     => base_path(),
                'RIP_BIN_WORKING_PATH' => $binPath,
                'APP_BASE_PATH'        => base_path(),
                'RIP_HOST'             => $this->getHost(),
                'RIP_PORT'             => $this->getPort(),
                'RIP_WORKERS'          => $workers,
                'RIP_WATCH'           => $watch ?? 0
            ]
        );
        $process->start();
        return $this->runServer($process, $inspector, 'ripple');
    }

    /**
     * @return bool
     */
    public function stopServer(): bool
    {
        return app(RippleServerProcessInspector::class)->stopServer();
    }

    /**
     * @param \Symfony\Component\Process\Process $server
     *
     * @return void
     */
    protected function writeServerOutput(Process $server): void
    {
        [$output, $errorOutput] = $this->getServerOutput($server);
        if ($output) {
            $this->output->write($output);
        }

        if ($errorOutput) {
            Output::error($errorOutput);
        }
    }
}
