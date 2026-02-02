<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\Tests\Unit\SchemaLoader;

use BehatOpenApiValidator\SchemaLoader\Exception\SchemaLoaderException;
use BehatOpenApiValidator\SchemaLoader\GithubSchemaLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class GithubSchemaLoaderTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private RequestFactoryInterface&MockObject $requestFactory;
    private RequestInterface&MockObject $request;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->requestFactory = $this->createMock(RequestFactoryInterface::class);
        $this->request = $this->createMock(RequestInterface::class);
    }

    #[Test]
    public function itThrowsExceptionForInvalidGithubUrl(): void
    {
        $loader = new GithubSchemaLoader(
            [['url' => 'https://example.com/invalid', 'token_env' => null]],
            $this->httpClient,
            $this->requestFactory
        );

        $this->expectException(SchemaLoaderException::class);
        $this->expectExceptionMessage('Invalid GitHub URL format');

        $loader->loadSchemas();
    }

    #[Test]
    public function itThrowsExceptionWhenNoSchemasFound(): void
    {
        $this->setupRequestFactoryMock();

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn(json_encode([
            ['name' => 'readme.txt', 'path' => 'schemas/readme.txt', 'type' => 'file', 'download_url' => 'https://raw.github.com/readme.txt'],
        ], JSON_THROW_ON_ERROR));

        $response->method('getBody')->willReturn($stream);

        $this->httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($response);

        $loader = new GithubSchemaLoader(
            [['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => null]],
            $this->httpClient,
            $this->requestFactory
        );

        $this->expectException(SchemaLoaderException::class);
        $this->expectExceptionMessage('No OpenAPI schema files were found');

        $loader->loadSchemas();
    }

    #[Test]
    public function itLoadsSingleYamlFile(): void
    {
        $yamlContent = "openapi: '3.0.0'\ninfo:\n  title: Test API";

        $this->setupRequestFactoryMock();

        $directoryResponse = $this->createResponseWithJson([
            ['name' => 'api.yaml', 'path' => 'schemas/api.yaml', 'type' => 'file', 'download_url' => 'https://raw.githubusercontent.com/file.yaml'],
        ]);

        $fileResponse = $this->createResponseWithContent($yamlContent);

        $this->httpClient->expects(self::exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($directoryResponse, $fileResponse);

        $loader = new GithubSchemaLoader(
            [['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => null]],
            $this->httpClient,
            $this->requestFactory
        );

        $schemas = $loader->loadSchemas();

        self::assertCount(1, $schemas);
        self::assertSame($yamlContent, $schemas['schemas/api.yaml']);
    }

    #[Test]
    public function itLoadsMultipleYamlFiles(): void
    {
        $yamlContent = "openapi: '3.0.0'";

        $this->setupRequestFactoryMock();

        $directoryResponse = $this->createResponseWithJson([
            ['name' => 'api.yaml', 'path' => 'schemas/api.yaml', 'type' => 'file', 'download_url' => 'https://raw.github.com/1.yaml'],
            ['name' => 'api2.yml', 'path' => 'schemas/api2.yml', 'type' => 'file', 'download_url' => 'https://raw.github.com/2.yml'],
        ]);

        $fileResponse1 = $this->createResponseWithContent($yamlContent);
        $fileResponse2 = $this->createResponseWithContent($yamlContent);

        $this->httpClient->expects(self::exactly(3))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($directoryResponse, $fileResponse1, $fileResponse2);

        $loader = new GithubSchemaLoader(
            [['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => null]],
            $this->httpClient,
            $this->requestFactory
        );

        $schemas = $loader->loadSchemas();

        self::assertCount(2, $schemas);
        self::assertArrayHasKey('schemas/api.yaml', $schemas);
        self::assertArrayHasKey('schemas/api2.yml', $schemas);
    }

    #[Test]
    public function itSkipsNonYamlFiles(): void
    {
        $yamlContent = "openapi: '3.0.0'";

        $this->setupRequestFactoryMock();

        $directoryResponse = $this->createResponseWithJson([
            ['name' => 'api.yaml', 'path' => 'schemas/api.yaml', 'type' => 'file', 'download_url' => 'https://raw.github.com/1.yaml'],
            ['name' => 'readme.txt', 'path' => 'schemas/readme.txt', 'type' => 'file', 'download_url' => 'https://raw.github.com/2.txt'],
        ]);

        $fileResponse = $this->createResponseWithContent($yamlContent);

        $this->httpClient->expects(self::exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($directoryResponse, $fileResponse);

        $loader = new GithubSchemaLoader(
            [['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => null]],
            $this->httpClient,
            $this->requestFactory
        );

        $schemas = $loader->loadSchemas();

        self::assertCount(1, $schemas);
        self::assertArrayHasKey('schemas/api.yaml', $schemas);
        self::assertArrayNotHasKey('schemas/readme.txt', $schemas);
    }

    #[Test]
    public function itHandlesRecursiveDirectories(): void
    {
        $yamlContent = "openapi: '3.0.0'";

        $this->setupRequestFactoryMock();

        $rootResponse = $this->createResponseWithJson([
            ['name' => 'subdir', 'path' => 'schemas/subdir', 'type' => 'dir', 'download_url' => null],
        ]);

        $subdirResponse = $this->createResponseWithJson([
            ['name' => 'api.yaml', 'path' => 'schemas/subdir/api.yaml', 'type' => 'file', 'download_url' => 'https://raw.github.com/file.yaml'],
        ]);

        $fileResponse = $this->createResponseWithContent($yamlContent);

        $this->httpClient->expects(self::exactly(3))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($rootResponse, $subdirResponse, $fileResponse);

        $loader = new GithubSchemaLoader(
            [['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => null]],
            $this->httpClient,
            $this->requestFactory
        );

        $schemas = $loader->loadSchemas();

        self::assertCount(1, $schemas);
        self::assertArrayHasKey('schemas/subdir/api.yaml', $schemas);
    }

    #[Test]
    public function itAddsAuthorizationHeaderWhenTokenProvided(): void
    {
        $_ENV['GITHUB_TOKEN'] = 'test-token-123';

        $request = $this->createMock(RequestInterface::class);

        $authHeaderAdded = false;
        $request->method('withHeader')
            ->willReturnCallback(static function (string $name, string $value) use ($request, &$authHeaderAdded): RequestInterface {
                if ($name === 'Authorization' && $value === 'Bearer test-token-123') {
                    $authHeaderAdded = true;
                }
                return $request;
            });

        $this->requestFactory->method('createRequest')
            ->willReturn($request);

        $response = $this->createResponseWithJson([
            ['name' => 'readme.txt', 'path' => 'schemas/readme.txt', 'type' => 'file', 'download_url' => 'https://raw.github.com/readme.txt'],
        ]);

        $this->httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($response);

        $loader = new GithubSchemaLoader(
            [['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => 'GITHUB_TOKEN']],
            $this->httpClient,
            $this->requestFactory
        );

        try {
            $loader->loadSchemas();
        } catch (SchemaLoaderException) {
            // Expected exception for no schemas
        }

        self::assertTrue($authHeaderAdded, 'Authorization header was not added');
        unset($_ENV['GITHUB_TOKEN']);
    }

    #[Test]
    public function itThrowsExceptionOnHttpClientError(): void
    {
        $this->setupRequestFactoryMock();

        $exception = new class extends \Exception implements ClientExceptionInterface {};

        $this->httpClient->expects(self::once())
            ->method('sendRequest')
            ->willThrowException($exception);

        $loader = new GithubSchemaLoader(
            [['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => null]],
            $this->httpClient,
            $this->requestFactory
        );

        $this->expectException(SchemaLoaderException::class);
        $this->expectExceptionMessage('GitHub API request failed');

        $loader->loadSchemas();
    }

    #[Test]
    public function itThrowsExceptionOnNon2xxStatusCode(): void
    {
        $this->setupRequestFactoryMock();

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);
        $response->method('getReasonPhrase')->willReturn('Not Found');
        $response->method('getHeaderLine')->willReturn('');

        $this->httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($response);

        $loader = new GithubSchemaLoader(
            [['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => null]],
            $this->httpClient,
            $this->requestFactory
        );

        $this->expectException(SchemaLoaderException::class);
        $this->expectExceptionMessage('HTTP 404 Not Found');

        $loader->loadSchemas();
    }

    #[Test]
    public function itHandlesRateLimitError(): void
    {
        $this->setupRequestFactoryMock();

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(403);
        $response->method('getHeaderLine')
            ->willReturnMap([
                ['X-RateLimit-Remaining', '0'],
                ['X-RateLimit-Reset', '1234567890'],
            ]);

        $this->httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($response);

        $loader = new GithubSchemaLoader(
            [['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => null]],
            $this->httpClient,
            $this->requestFactory
        );

        $this->expectException(SchemaLoaderException::class);
        $this->expectExceptionMessage('GitHub API rate limit exceeded');

        $loader->loadSchemas();
    }

    #[Test]
    public function itThrowsExceptionOnInvalidJsonResponse(): void
    {
        $this->setupRequestFactoryMock();

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn('invalid json');

        $response->method('getBody')->willReturn($stream);

        $this->httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($response);

        $loader = new GithubSchemaLoader(
            [['url' => 'https://github.com/owner/repo/tree/main/schemas', 'token_env' => null]],
            $this->httpClient,
            $this->requestFactory
        );

        $this->expectException(SchemaLoaderException::class);
        $this->expectExceptionMessage('Invalid JSON response');

        $loader->loadSchemas();
    }

    #[Test]
    public function itLoadsFromMultipleSources(): void
    {
        $yamlContent = "openapi: '3.0.0'";

        $this->setupRequestFactoryMock();

        $dir1Response = $this->createResponseWithJson([
            ['name' => 'api1.yaml', 'path' => 'schemas/api1.yaml', 'type' => 'file', 'download_url' => 'https://raw.github.com/1.yaml'],
        ]);

        $file1Response = $this->createResponseWithContent($yamlContent);

        $dir2Response = $this->createResponseWithJson([
            ['name' => 'api2.yaml', 'path' => 'schemas/api2.yaml', 'type' => 'file', 'download_url' => 'https://raw.github.com/2.yaml'],
        ]);

        $file2Response = $this->createResponseWithContent($yamlContent);

        $this->httpClient->expects(self::exactly(4))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($dir1Response, $file1Response, $dir2Response, $file2Response);

        $loader = new GithubSchemaLoader(
            [
                ['url' => 'https://github.com/owner/repo1/tree/main/schemas', 'token_env' => null],
                ['url' => 'https://github.com/owner/repo2/tree/main/schemas', 'token_env' => null],
            ],
            $this->httpClient,
            $this->requestFactory
        );

        $schemas = $loader->loadSchemas();

        self::assertCount(2, $schemas);
    }

    private function setupRequestFactoryMock(): void
    {
        $this->request->method('withHeader')->willReturnSelf();

        $this->requestFactory->method('createRequest')
            ->willReturn($this->request);
    }

    /**
     * @param list<array{name: string, path: string, type: string, download_url: string|null}> $items
     */
    private function createResponseWithJson(array $items): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn(json_encode($items, JSON_THROW_ON_ERROR));

        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    private function createResponseWithContent(string $content): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn($content);

        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
