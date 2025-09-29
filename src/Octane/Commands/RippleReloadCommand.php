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

use Laravel\Octane\Commands\StopCommand;
use Laravel\Ripple\Octane\RippleServerProcessInspector;
use Symfony\Component\Console\Attribute\AsCommand;

use function app;

#[AsCommand(name: 'octane:reload@ripple')]
class RippleReloadCommand extends StopCommand
{
    /**
     * The command's signature.
     * @var string
     */
    public $signature = 'octane:reload@ripple';

    /**
     * The command's description.
     * @var string
     */
    public $description = 'Stop the Octane server';

    /**
     * Handle the command.
     * @return int
     */
    public function handle(): int
    {
        $server = 'ripple';
        return match ($server) {
            'ripple' => $this->reloadRippleServer(),
            default  => parent::handle()
        };
    }

    /**
     * @return int
     */
    protected function reloadRippleServer(): int
    {
        $inspector = app(RippleServerProcessInspector::class);

        if (!$inspector->serverIsRunning()) {
            $this->components->error('Octane server is not running.');
            return 1;
        }

        $this->components->info('Reloading workers...');
        $inspector->reloadServer();

        return 0;
    }
}
