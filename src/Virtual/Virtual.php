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

use function array_merge;
use function Co\proc;
use function file_get_contents;
use function getenv;
use function putenv;
use function uniqid;

use const PHP_BINARY;

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
        putenv("RIP_VIRTUAL_ID={$this->id}");
    }

    /**
     * @param array $envs
     *
     * @return \Ripple\Proc\Session|false
     */
    public function launch(array $envs = []): Session|false
    {
        foreach (array_merge(getenv(), $envs) as $key => $value) {
            putenv("{$key}={$value}");
        }

        $this->guard();
        return $this->session;
    }

    /**
     * @return Session
     */
    public function guard(): Session
    {
        $this->session = proc(PHP_BINARY);
        $this->session->input(file_get_contents($this->virtualPath));
        $this->session->inputEot();
        $this->session->onClose = fn () => $this->onTerminate();
        return $this->session;
    }

    /**
     * @return void
     */
    private function onTerminate(): void
    {
        $this->guard();
    }

    /**
     * @return void
     */
    public function stop(): void
    {
        unset($this->session->onClose);
        $this->session->close();
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
