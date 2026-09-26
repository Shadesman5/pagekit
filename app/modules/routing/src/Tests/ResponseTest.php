<?php

declare(strict_types=1);

namespace Pagekit\Routing\Tests;

use Pagekit\Routing\Response;
use Pagekit\Routing\UrlProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

class ResponseTest extends TestCase
{
    public function testCreateAndInvokeBuildAnHttpResponse(): void
    {
        $response = $this->response();

        $created = $response->create('hello', 201, ['X-Test' => '1']);

        $this->assertSame('hello', $created->getContent());
        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame('1', $created->headers->get('X-Test'));

        $invoked = $response('body', 204, ['X-Empty' => '1']);

        $this->assertSame('body', $invoked->getContent());
        $this->assertSame(204, $invoked->getStatusCode());
        $this->assertSame('1', $invoked->headers->get('X-Empty'));
    }

    public function testJsonEncodesThePayload(): void
    {
        $json = $this->response()->json(['ok' => true], 201, ['X-Json' => '1']);

        $this->assertSame(201, $json->getStatusCode());
        $this->assertSame('{"ok":true}', $json->getContent());
        $this->assertSame('1', $json->headers->get('X-Json'));
    }

    public function testRedirectUsesTheResolvedUrl(): void
    {
        $url = $this->createMock(UrlProvider::class);
        $url->expects($this->once())
            ->method('get')
            ->with('@blog/id', ['id' => 5])
            ->willReturn('/blog/5');

        $redirect = (new Response($url))->redirect('@blog/id', ['id' => 5], 301, ['X-From' => 'test']);

        $this->assertSame(301, $redirect->getStatusCode());
        $this->assertSame('/blog/5', $redirect->getTargetUrl());
        $this->assertSame('test', $redirect->headers->get('X-From'));
    }

    public function testRedirectRefusesAnUnresolvedUrl(): void
    {
        $url = $this->createMock(UrlProvider::class);
        $url->method('get')->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot redirect to "@missing": URL or route could not be resolved.');

        (new Response($url))->redirect('@missing');
    }

    public function testStreamSendsTheCallback(): void
    {
        $streamed = $this->response()->stream(static function (): void {
            echo 'chunk';
        }, 202, ['X-Stream' => '1']);

        $this->assertSame(202, $streamed->getStatusCode());
        $this->assertSame('1', $streamed->headers->get('X-Stream'));

        ob_start();
        $streamed->sendContent();
        $body = ob_get_clean();

        $this->assertSame('chunk', $body);
    }

    public function testDownloadAttachesTheFileUnderTheGivenName(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pk-download-');
        $this->assertIsString($file);
        file_put_contents($file, 'payload');

        try {
            $download = $this->response()->download($file, 'report.txt');

            $this->assertSame($file, $download->getFile()->getPathname());

            $disposition = $download->headers->get('content-disposition');
            $this->assertIsString($disposition);
            $this->assertStringContainsString('attachment', $disposition);
            $this->assertStringContainsString('report.txt', $disposition);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testDownloadRefusesAMissingFile(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('does not exist');

        $this->response()->download(sys_get_temp_dir().'/pk-download-missing-'.uniqid('', true));
    }

    private function response(): Response
    {
        return new Response($this->createMock(UrlProvider::class));
    }
}
