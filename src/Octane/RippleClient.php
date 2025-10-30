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

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Laravel\Ripple\Built\Response\IteratorResponse;
use Ripple\Runtime\Support\Stdin;
use Ripple\Stream\Exception\ConnectionException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

use function fopen;
use function file_exists;
use function is_array;

use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;

/**
 *
 */
class RippleClient implements Client
{
    /**
     * @param RequestContext $context
     * @return array
     */
    public function marshalRequest(RequestContext $context): array
    {
        $rippleHttpRequest = $context->data['rippleHttpRequest'];
        $request       = new Request(
            $rippleHttpRequest->GET,
            $rippleHttpRequest->POST,
            [],
            $rippleHttpRequest->COOKIE,
            $this->parseFiles($rippleHttpRequest->FILES),
            $rippleHttpRequest->SERVER,
            $rippleHttpRequest->CONTENT,
        );
        $request->attributes->set('rippleHttpRequest', $rippleHttpRequest);

        return [
            $request,
            $context
        ];
    }

    /**
     * @param RequestContext $context
     * @param OctaneResponse $response
     * @return void
     * @throws ConnectionException
     */
    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        /*** @var \Ripple\Net\Http\Request $rippleHttpRequest */
        $rippleHttpRequest   = $context->data['rippleHttpRequest'];
        $rippleResponse  = $rippleHttpRequest->response();
        $laravelResponse = $response->response;
        $rippleResponse->setStatusCode($laravelResponse->getStatusCode());

        foreach ($laravelResponse->headers->allPreserveCaseWithoutCookies() as $key => $value) {
            $rippleResponse->withHeader($key, $value);
        }

        foreach ($laravelResponse->headers->getCookies() as $cookie) {
            $rippleResponse->withCookie($cookie->getName(), ['value' => $cookie->__toString()]);
        }

        if ($laravelResponse instanceof BinaryFileResponse) {
            $rippleResponse->withBody(fopen($laravelResponse->getFile()->getPathname(), 'r+'));
        } elseif ($laravelResponse instanceof IteratorResponse) {
            $rippleResponse->withBody($laravelResponse->getIterator());
        } else {
            $rippleResponse->withBody($laravelResponse->getContent());
        }

        $rippleResponse->send();
    }

    /**
     * @param Throwable $e
     * @param Application $app
     * @param Request $request
     * @param RequestContext $context
     * @return void
     */
    public function error(Throwable $e, Application $app, Request $request, RequestContext $context): void
    {
        Stdin::println($e->getMessage());
    }

    /*** @return array<string, array<UploadedFile>> */
    protected function parseFiles(array $files): array
    {
        if (empty($files)) {
            return [];
        }

        $parsed = [];
        foreach ($files as $name => $items) {
            if (!is_array($items)) {
                continue;
            }

            if (isset($items[0]['path']) && is_array($items[0])) {
                $parsed[$name] = [];
                foreach ($items as $item) {
                    $path = $item['path'];
                    $parsed[$name][] = new UploadedFile(
                        $path,
                        $item['fileName'] ?? '',
                        $item['contentType'] ?? 'application/octet-stream',
                        file_exists($path) ? UPLOAD_ERR_OK : UPLOAD_ERR_NO_FILE,
                        true
                    );
                }
            } else {
                $parsed[$name] = $items;
            }
        }

        return $parsed;
    }
}
