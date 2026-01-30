<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\Tests\Unit\SchemaLoader;

use BehatOpenApiValidator\SchemaLoader\GithubSchemaLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class GithubSchemaLoaderTest extends TestCase
{
    #[Test]
    public function itReturnsEmptyArrayWhenNoSourcesConfigured(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);

        $loader = new GithubSchemaLoader([], $httpClient, $requestFactory);

        self::assertSame([], $loader->loadSchemas());
    }

    #[Test]
    public function itSkipsInvalidGithubUrls(): void
    {
        $sources = [
            ['url' => 'https://invalid-url.com/path', 'token_env' => null],
        ];

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::never())->method('sendRequest');

        $requestFactory = $this->createMock(RequestFactoryInterface::class);

        $loader = new GithubSchemaLoader($sources, $httpClient, $requestFactory);

        self::assertSame([], $loader->loadSchemas());
    }

    #[Test]
    public function itFetchesSchemasFromGithub(): void
    {
        $sources = [
            ['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => null],
        ];

        $directoryResponse = $this->createMockResponse((string) json_encode([
            [
                'type' => 'file',
                'name' => 'api.yaml',
                'path' => 'schemas/api.yaml',
                'download_url' => 'https://raw.githubusercontent.com/owner/repo/main/schemas/api.yaml',
            ],
        ], JSON_THROW_ON_ERROR));

        $fileResponse = $this->createMockResponse('openapi: 3.0.0');

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willReturnOnConsecutiveCalls($directoryResponse, $fileResponse);

        $loader = new GithubSchemaLoader($sources, $httpClient, $requestFactory);
        $schemas = $loader->loadSchemas();

        self::assertCount(1, $schemas);
        self::assertArrayHasKey('schemas/api.yaml', $schemas);
        self::assertSame('openapi: 3.0.0', $schemas['schemas/api.yaml']);
    }

    #[Test]
    public function itRecursivelyFetchesDirectories(): void
    {
        $sources = [
            ['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => null],
        ];

        $rootDirectoryResponse = $this->createMockResponse((string) json_encode([
            [
                'type' => 'dir',
                'name' => 'v1',
                'path' => 'schemas/v1',
            ],
        ], JSON_THROW_ON_ERROR));

        $subDirectoryResponse = $this->createMockResponse((string) json_encode([
            [
                'type' => 'file',
                'name' => 'users.yaml',
                'path' => 'schemas/v1/users.yaml',
                'download_url' => 'https://raw.githubusercontent.com/owner/repo/main/schemas/v1/users.yaml',
            ],
        ], JSON_THROW_ON_ERROR));

        $fileResponse = $this->createMockResponse('openapi: 3.0.0');

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willReturnOnConsecutiveCalls($rootDirectoryResponse, $subDirectoryResponse, $fileResponse);

        $loader = new GithubSchemaLoader($sources, $httpClient, $requestFactory);
        $schemas = $loader->loadSchemas();

        self::assertCount(1, $schemas);
        self::assertArrayHasKey('schemas/v1/users.yaml', $schemas);
    }

    #[Test]
    public function itHandlesClientExceptions(): void
    {
        $sources = [
            ['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => null],
        ];

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willThrowException($this->createMock(ClientExceptionInterface::class));

        $loader = new GithubSchemaLoader($sources, $httpClient, $requestFactory);
        $schemas = $loader->loadSchemas();

        self::assertSame([], $schemas);
    }

    #[Test]
    public function itAddsAuthorizationHeaderWhenTokenProvided(): void
    {
        $_ENV['GITHUB_TOKEN'] = 'test-token';

        $sources = [
            ['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => 'GITHUB_TOKEN'],
        ];

        $directoryResponse = $this->createMockResponse('[]');

        $request = $this->createMock(RequestInterface::class);
        $request->expects(self::atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(static function ($name, $value) use ($request) {
                if ($name === 'Authorization') {
                    self::assertSame('Bearer test-token', $value);
                }
                return $request;
            });

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($directoryResponse);

        $loader = new GithubSchemaLoader($sources, $httpClient, $requestFactory);
        $loader->loadSchemas();

        unset($_ENV['GITHUB_TOKEN']);
    }

    #[Test]
    public function itOnlyFetchesYamlFiles(): void
    {
        $sources = [
            ['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => null],
        ];

        $directoryResponse = $this->createMockResponse((string) json_encode([
            [
                'type' => 'file',
                'name' => 'api.yaml',
                'path' => 'schemas/api.yaml',
                'download_url' => 'https://raw.githubusercontent.com/owner/repo/main/schemas/api.yaml',
            ],
            [
                'type' => 'file',
                'name' => 'users.yml',
                'path' => 'schemas/users.yml',
                'download_url' => 'https://raw.githubusercontent.com/owner/repo/main/schemas/users.yml',
            ],
            [
                'type' => 'file',
                'name' => 'readme.md',
                'path' => 'schemas/readme.md',
                'download_url' => 'https://raw.githubusercontent.com/owner/repo/main/schemas/readme.md',
            ],
        ], JSON_THROW_ON_ERROR));

        $yamlResponse = $this->createMockResponse('openapi: 3.0.0');
        $ymlResponse = $this->createMockResponse('openapi: 3.0.0');

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willReturnOnConsecutiveCalls($directoryResponse, $yamlResponse, $ymlResponse);

        $loader = new GithubSchemaLoader($sources, $httpClient, $requestFactory);
        $schemas = $loader->loadSchemas();

        self::assertCount(2, $schemas);
        self::assertArrayHasKey('schemas/api.yaml', $schemas);
        self::assertArrayHasKey('schemas/users.yml', $schemas);
        self::assertArrayNotHasKey('schemas/readme.md', $schemas);
    }

    private function createMockResponse(string $content): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn($content);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
