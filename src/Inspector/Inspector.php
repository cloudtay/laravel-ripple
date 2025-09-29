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

use Ripple\Serial\Zx7e;

use function fclose;
use function fopen;
use function fwrite;

class Inspector
{
    /**
     *
     */
    public function __construct(public readonly Client $client)
    {
    }

    /**
     * @return bool
     */
    public function serverIsRunning(): bool
    {
        return $this->client->serverIsRunning();
    }

    /**
     * @return void
     */
    public function reloadServer(): void
    {
        if (!$this->serverIsRunning()) {
            return;
        }
        $channel = fopen($this->client->channelPath, 'w');
        fwrite($channel, Zx7e::encode('reload'));
        fclose($channel);
    }

    /**
     * @return bool
     */
    public function stopServer(): bool
    {
        if (!$this->serverIsRunning()) {
            return true;
        }

        $channel = fopen($this->client->channelPath, 'w');
        fwrite($channel, Zx7e::encode('stop'));
        fclose($channel);
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

        $channel = fopen($this->client->channelPath, 'w');
        fwrite($channel, Zx7e::encode('restart'));
        fclose($channel);
    }
}
