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
use Laravel\Octane\Commands\StatusCommand;
use Laravel\Ripple\Octane\RippleServerProcessInspector;
use Symfony\Component\Console\Attribute\AsCommand;

use function app;

#[AsCommand(name: 'octane:status@ripple')]
class RippleStatusCommand extends StatusCommand
{
    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'octane:status@ripple';

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
        $server    = 'ripple';
        $isRunning = match ($server) {
            'ripple' => $this->isRippleServerRunning(),
            default  => parent::handle()
        };

        $isRunning
            ? $this->components->info('Octane server is running.')
            : $this->components->info('Octane server is not running.');

        return 0;
    }

    /**
     * @return bool
     */
    private function isRippleServerRunning(): bool
    {
        try {
            return app(RippleServerProcessInspector::class)->serverIsRunning();
        } catch (BindingResolutionException $e) {
            $this->components->error('Unable to resolve Ripple server process inspector.');
            return false;
        }
    }
}
