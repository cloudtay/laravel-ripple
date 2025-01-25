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

namespace Laravel\Ripple\Virtual;

use Ripple\Channel\Channel;
use Ripple\Proc\Session;
use Throwable;

use function array_merge;
use function Co\proc;
use function getenv;
use function putenv;
use function uniqid;

use const PHP_BINARY;
use const SIGINT;

class Virtual
{
    /*** @var string */
    public string $id;

    /*** @var \Ripple\Channel\Channel */
    public Channel $channel;

    /*** @var Session */
    public Session $session;

    /**
     *
     */
    public function __construct(public readonly string $virtualPath)
    {
        $this->id      = uniqid();
        $this->channel = \Co\channel($this->id, true);
    }

    /**
     * @param array $envs
     *
     * @return \Ripple\Proc\Session
     */
    public function launch(array $envs = []): Session
    {
        foreach (array_merge(getenv(), $envs) as $key => $value) {
            putenv("{$key}={$value}");
        }

        $launch = fn () => proc([PHP_BINARY, $this->virtualPath]);

        $session          = $launch();
        $session->onClose = function () use ($launch, $session) {
            unset($session->onClose);
            $this->session = $launch();
        };

        return $this->session = $session;
    }

    /**
     * @return void
     */
    public function stop(): void
    {
        unset($this->session->onClose);
        $this->channel->send('stop');
        try {
            \Co\sleep(0.1);
            if ($this->session->getStatus('running')) {
                \Co\sleep(1);
                $this->session->inputSignal(SIGINT);
            }
        } catch (Throwable) {
        }
    }

    /**
     *
     */
    public function __destruct()
    {
        $this->session->close();
        $this->channel->close();
    }
}
