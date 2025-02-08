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

use Illuminate\Contracts\Container\BindingResolutionException;
use Laravel\Octane\Commands\StopCommand;
use Laravel\Ripple\Inspector\Inspector;
use Symfony\Component\Console\Attribute\AsCommand;

use function app;

#[AsCommand(name: 'octane:stop@ripple')]
class RippleStopCommand extends StopCommand
{
    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'octane:stop@ripple';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Stop the Octane server';

    /**
     * Handle the command.
     *
     * @return int
     */
    public function handle(): int
    {
        $server = 'ripple';
        return match ($server) {
            'ripple' => $this->stopRippleServer(),
            default  => parent::handle()
        };
    }

    /**
     * @return int
     */
    protected function stopRippleServer(): int
    {
        try {
            $inspector = app(Inspector::class);
            if (!$inspector->serverIsRunning()) {
                $this->components->error('Ripple server is not running.');
                return 1;
            }

            $this->components->info('Stopping server...');
            $inspector->stopServer();
        } catch (BindingResolutionException $e) {
            return 1;
        }

        return 0;
    }
}
