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
use Laravel\Ripple\Util;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ripple\Net\Http\Response;
use Ripple\Runtime\Support\Stdin;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

use function is_array;
use function is_object;

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
        /*** @var ServerRequestInterface $rippleHttpRequest */
        $rippleHttpRequest = $context->data['rippleHttpRequest'];
        $request = $this->toLaravelRequest($rippleHttpRequest);
        $request->attributes->set('rippleHttpRequest', $rippleHttpRequest);
        $request->attributes->set('httpRequest', $rippleHttpRequest);

        return [
            $request,
            $context
        ];
    }

    /**
     * @param RequestContext $context
     * @param OctaneResponse $response
     * @return void
     */
    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        $laravelResponse = $response->response;

        $context->data['rippleResponse'] = Util::toRippleResponse($laravelResponse, $response->outputBuffer);
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
        $context->data['rippleResponse'] = new Response(500, ['Content-Type' => 'text/plain'], $e->getMessage());
    }

    public function pullResponse(RequestContext $context): ?ResponseInterface
    {
        $response = $context->data['rippleResponse'] ?? null;
        unset($context->data['rippleResponse']);

        return $response;
    }

    protected function toLaravelRequest(ServerRequestInterface $request): Request
    {
        $parsedBody = $request->getParsedBody();

        return new Request(
            $request->getQueryParams(),
            is_array($parsedBody) ? $parsedBody : (is_object($parsedBody) ? (array)$parsedBody : []),
            [],
            $request->getCookieParams(),
            $this->parseFiles($request->getUploadedFiles()),
            $request->getServerParams(),
            (string)$request->getBody(),
        );
    }

    /*** @return array<string, array<UploadedFile>> */
    protected function parseFiles(array $files): array
    {
        return Util::normalizeRippleFiles($files);
    }
}
