<?php declare(strict_types=1);

namespace Ripple\Driver\Laravel\Virtual;

use Ripple\Channel\Channel;
use Ripple\Proc\Session;
use Ripple\Utils\Output;

use function base_path;
use function Co\proc;
use function file_get_contents;
use function fwrite;
use function putenv;
use function uniqid;

use const PHP_BINARY;
use const STDOUT;

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
    public function __construct()
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
        putenv('RIP_VIRTUAL_ID=' . $this->id);

        $session            = proc(PHP_BINARY);
        $session->onMessage = static function (string $data) {
            fwrite(STDOUT, $data);
        };

        $session->onErrorMessage = static function (string $data) {
            Output::warning($data);
        };

        $session->write(file_get_contents(__DIR__ . '/Guide.php'));
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
