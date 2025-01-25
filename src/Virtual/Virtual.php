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

use function base_path;
use function Co\proc;
use function file_get_contents;
use function putenv;
use function uniqid;
use function getenv;

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
    }

    /**
     * @return \Ripple\Proc\Session
     */
    public function launch(): Session
    {
        putenv('RIP_PROJECT_PATH=' . base_path());
        putenv('RIP_BIN_WORKING_PATH=' . base_path());
        putenv('RIP_VIRTUAL_ID=' . $this->id);

        foreach (getenv() as $key => $value) {
            putenv("{$key}={$value}");
        }

        $session            = proc(PHP_BINARY);
        $session->write(file_get_contents($this->virtualPath));
        $session->inputEot();
        return $this->session = $session;
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
