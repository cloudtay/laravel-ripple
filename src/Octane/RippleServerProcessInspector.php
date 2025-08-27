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

namespace Laravel\Ripple\Octane;

use Laravel\Octane\Contracts\ServerProcessInspector as ServerProcessInspectorContract;
use Laravel\Ripple\Inspector\Client;

/**
 *
 */
class RippleServerProcessInspector implements ServerProcessInspectorContract
{
    /**
     * @param Client $client
     */
    public function __construct(protected readonly Client $client)
    {
    }

    /**
     * @return bool
     */
    public function serverIsRunning(): bool
    {
        return $this->client->inspector->serverIsRunning();
    }

    /**
     * @return void
     */
    public function reloadServer(): void
    {
        $this->client->inspector->reloadServer();
    }

    /**
     * @return bool
     */
    public function stopServer(): bool
    {
        return $this->client->inspector->stopServer();
    }
}
