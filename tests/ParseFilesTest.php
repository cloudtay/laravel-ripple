<?php declare(strict_types=1);

use Laravel\Ripple\HttpWorker;
use Laravel\Ripple\Octane\RippleClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ParseFilesTest extends TestCase
{
    public function test_http_worker_converts_single_file_payload_to_uploaded_file(): void
    {
        $files = [
            'file' => [
                'name' => 'file',
                'isFile' => true,
                'fileName' => 'icon.png',
                'contentType' => 'image/png',
                'path' => __FILE__,
            ],
        ];

        $parsed = (new ExposedHttpWorker())->exposedParseFiles($files);

        self::assertArrayHasKey('file', $parsed);
        self::assertInstanceOf(UploadedFile::class, $parsed['file']);
        self::assertSame('icon.png', $parsed['file']->getClientOriginalName());
        self::assertSame('image/png', $parsed['file']->getClientMimeType());
    }

    public function test_ripple_client_converts_single_file_payload_to_uploaded_file(): void
    {
        $files = [
            'file' => [
                'name' => 'file',
                'isFile' => true,
                'fileName' => 'icon.png',
                'contentType' => 'image/png',
                'path' => __FILE__,
            ],
        ];

        $parsed = (new ExposedRippleClient())->exposedParseFiles($files);

        self::assertArrayHasKey('file', $parsed);
        self::assertInstanceOf(UploadedFile::class, $parsed['file']);
        self::assertSame('icon.png', $parsed['file']->getClientOriginalName());
        self::assertSame('image/png', $parsed['file']->getClientMimeType());
    }

    public function test_http_worker_keeps_multi_file_payload_as_uploaded_file_list(): void
    {
        $files = [
            'file' => [
                [
                    'fileName' => 'icon.png',
                    'contentType' => 'image/png',
                    'path' => __FILE__,
                ],
                [
                    'fileName' => 'cover.jpg',
                    'contentType' => 'image/jpeg',
                    'path' => __FILE__,
                ],
            ],
        ];

        $parsed = (new ExposedHttpWorker())->exposedParseFiles($files);

        self::assertCount(2, $parsed['file']);
        self::assertContainsOnlyInstancesOf(UploadedFile::class, $parsed['file']);
        self::assertSame('cover.jpg', $parsed['file'][1]->getClientOriginalName());
    }
}

final class ExposedHttpWorker extends HttpWorker
{
    public function __construct()
    {
    }

    public function exposedParseFiles(array $files): array
    {
        return $this->parseFiles($files);
    }
}

final class ExposedRippleClient extends RippleClient
{
    public function exposedParseFiles(array $files): array
    {
        return $this->parseFiles($files);
    }
}
