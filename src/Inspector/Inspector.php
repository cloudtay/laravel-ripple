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

use function base_path;
use function Co\channel;

class Inspector
{
    /**
     *
     */
    public function __construct(public readonly Client $client)
    {
    }

    /**
     * @return void
     */
    public function reloadServer(): void
    {
        if (!$this->serverIsRunning()) {
            return;
        }

        $channel = channel(base_path());
        $channel->send('reload');
    }

    /**
     * @return bool
     */
    public function serverIsRunning(): bool
    {
        if ($locked = $this->client->lock->shareable(false)) {
            $this->client->lock->unlock();
        }

        return $this->client->owner || !$locked;
    }

    /**
     * @return bool
     */
    public function stopServer(): bool
    {
        if (!$this->serverIsRunning()) {
            return true;
        }

        $channel = channel(base_path());
        $channel->send('stop');
        return true;
    }

    /**
     * @return void
     */
    public function restartServer(): void
    {
        if (!$this->serverIsRunning()) {
            return;
        }

        $channel = channel(base_path());
        $channel->send('restart');
    }
}
