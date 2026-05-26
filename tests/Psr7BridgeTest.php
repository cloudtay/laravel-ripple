<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Laravel\Ripple\Built\Response\IteratorBodyStream;
use Laravel\Ripple\HttpWorker;
use Laravel\Ripple\Octane\RippleClient;
use Laravel\Ripple\Util;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\ServerRequest;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class Psr7BridgeTest extends TestCase
{
    public function test_http_worker_builds_laravel_request_from_psr_server_request(): void
    {
        $psrRequest = new ServerRequest(
            method: 'POST',
            headers: ['Content-Type' => 'text/plain'],
            body: 'payload',
            serverParams: ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'example.com'],
            queryParams: ['page' => '1'],
            parsedBody: ['name' => 'Taylor'],
            cookieParams: ['session' => 'abc'],
        );

        $laravelRequest = (new ExposedPsrHttpWorker())->exposedToLaravelRequest($psrRequest);

        self::assertSame(['page' => '1'], $laravelRequest->query->all());
        self::assertSame(['name' => 'Taylor'], $laravelRequest->request->all());
        self::assertSame(['session' => 'abc'], $laravelRequest->cookies->all());
        self::assertSame('payload', $laravelRequest->getContent());
        self::assertSame('POST', $laravelRequest->server->get('REQUEST_METHOD'));
    }

    public function test_ripple_client_marshals_psr_server_request_for_octane(): void
    {
        $psrRequest = new ServerRequest(
            method: 'POST',
            body: 'octane',
            serverParams: ['REQUEST_METHOD' => 'POST'],
            queryParams: ['q' => 'ripple'],
            parsedBody: ['ok' => 'yes'],
        );

        [$request, $context] = (new RippleClient())->marshalRequest(new RequestContext([
            'rippleHttpRequest' => $psrRequest,
        ]));

        self::assertInstanceOf(Request::class, $request);
        self::assertSame('ripple', $request->query->get('q'));
        self::assertSame('yes', $request->request->get('ok'));
        self::assertSame('octane', $request->getContent());
        self::assertSame($psrRequest, $request->attributes->get('rippleHttpRequest'));
        self::assertSame($psrRequest, $context->data['rippleHttpRequest']);
    }

    public function test_symfony_response_is_converted_to_ripple_psr_response(): void
    {
        $symfonyResponse = new SymfonyResponse('created', 201, ['X-Test' => ['one', 'two']]);
        $symfonyResponse->headers->setCookie(new Cookie('token', 'abc'));

        $rippleResponse = Util::toRippleResponse($symfonyResponse);

        self::assertSame(201, $rippleResponse->getStatusCode());
        self::assertSame(['one', 'two'], $rippleResponse->getHeader('X-Test'));
        self::assertStringContainsString('token=abc', $rippleResponse->getHeaderLine('Set-Cookie'));
        self::assertSame('created', (string)$rippleResponse->getBody());
    }

    public function test_ripple_client_stores_octane_response_for_server_handler(): void
    {
        $client = new RippleClient();
        $context = new RequestContext(['rippleHttpRequest' => new ServerRequest()]);

        $client->respond(
            $context,
            new OctaneResponse(new SymfonyResponse('ok', 202), 'buffer:')
        );

        $response = $client->pullResponse($context);

        self::assertSame(202, $response?->getStatusCode());
        self::assertSame('buffer:ok', (string)$response?->getBody());
        self::assertNull($client->pullResponse($context));
    }

    public function test_psr_uploaded_files_are_converted_to_symfony_uploaded_files(): void
    {
        $parsed = Util::normalizeRippleFiles([
            'file' => new FakePsrUploadedFile('avatar.png', 'image/png', 'image'),
        ]);

        self::assertInstanceOf(UploadedFile::class, $parsed['file']);
        self::assertSame('avatar.png', $parsed['file']->getClientOriginalName());
        self::assertSame('image/png', $parsed['file']->getClientMimeType());
        self::assertSame('image', $parsed['file']->getContent());
    }

    public function test_iterator_body_stream_reads_chunks_lazily(): void
    {
        $stream = new IteratorBodyStream((function () {
            yield 'ab';
            yield 'cd';
        })());

        self::assertFalse($stream->isSeekable());
        self::assertSame('abc', $stream->read(3));
        self::assertSame('d', $stream->getContents());
    }
}

final class ExposedPsrHttpWorker extends HttpWorker
{
    public function __construct()
    {
    }

    public function exposedToLaravelRequest(ServerRequestInterface $request): Request
    {
        return $this->toLaravelRequest($request);
    }
}

final class FakePsrUploadedFile implements UploadedFileInterface
{
    public function __construct(
        private readonly string $clientFilename,
        private readonly string $clientMediaType,
        private readonly string $content,
    ) {
    }

    public function getStream(): StreamInterface
    {
        return BodyStream::fromString($this->content);
    }

    public function moveTo(string $targetPath): void
    {
        \file_put_contents($targetPath, $this->content);
    }

    public function getSize(): ?int
    {
        return \strlen($this->content);
    }

    public function getError(): int
    {
        return \UPLOAD_ERR_OK;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}
