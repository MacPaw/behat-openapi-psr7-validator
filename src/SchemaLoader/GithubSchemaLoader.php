<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\SchemaLoader;

use BehatOpenApiValidator\SchemaLoader\Exception\SchemaLoaderException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class GithubSchemaLoader implements SchemaLoaderInterface
{
    /**
     * @param list<array{url: string, token_env: string|null}> $sources
     */
    public function __construct(
        private readonly array $sources,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory
    ) {}

    public function loadSchemas(): array
    {
        $schemas = [];

        foreach ($this->sources as $source) {
            $url = $source['url'];
            $tokenEnv = $source['token_env'] ?? null;
            $token = null;

            if ($tokenEnv !== null) {
                $envValue = $_ENV[$tokenEnv] ?? getenv($tokenEnv);
                $token = \is_string($envValue) ? $envValue : null;
            }

            $parsed = $this->parseGithubUrl($url);

            if ($parsed === null) {
                throw new SchemaLoaderException(
                    \sprintf(
                        'Invalid GitHub URL format: "%s". Expected: https://github.com/owner/repo/tree/branch/path',
                        $url
                    )
                );
            }

            $files = $this->fetchDirectoryContents(
                $parsed['owner'],
                $parsed['repo'],
                $parsed['path'],
                $parsed['ref'],
                $token
            );

            foreach ($files as $filePath => $content) {
                $schemas[$filePath] = $content;
            }
        }

        if ($schemas === []) {
            throw new SchemaLoaderException(
                'No OpenAPI schema files were found in the specified GitHub repositories.'
            );
        }

        return $schemas;
    }

    /**
     * @return array{owner: string, repo: string, path: string, ref: string}|null
     */
    private function parseGithubUrl(string $url): ?array
    {
        // Handle: https://github.com/Owner/Repo/tree/branch/path/to/folder
        $pattern = '#^https?://github\.com/([^/]+)/([^/]+)/tree/([^/]+)/(.+)$#';
        if (preg_match($pattern, $url, $matches)) {
            return [
                'owner' => $matches[1],
                'repo' => $matches[2],
                'ref' => $matches[3],
                'path' => $matches[4],
            ];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function fetchDirectoryContents(
        string $owner,
        string $repo,
        string $path,
        string $ref,
        ?string $token
    ): array {
        $apiUrl = \sprintf(
            'https://api.github.com/repos/%s/%s/contents/%s?ref=%s',
            $owner,
            $repo,
            $path,
            $ref
        );

        $request = $this->requestFactory->createRequest('GET', $apiUrl)
            ->withHeader('Accept', 'application/vnd.github.v3+json')
            ->withHeader('User-Agent', 'BehatOpenApiValidator');

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new SchemaLoaderException(
                \sprintf(
                    'GitHub API request failed for "%s" (%s): %s',
                    $apiUrl,
                    $token !== null && $token !== '' ? 'with authentication' : 'without authentication',
                    $e->getMessage()
                ),
                previous: $e
            );
        }

        $this->validateResponse($response, $apiUrl);
        $contents = $this->decodeJsonResponse($response, $apiUrl);

        $schemas = [];

        foreach ($contents as $item) {
            $type = $item['type'];
            $name = $item['name'];
            $itemPath = $item['path'];
            $downloadUrl = $item['download_url'];

            if ($type === 'file' && \is_string($downloadUrl) && $this->isYamlFile($name)) {
                $fileContent = $this->fetchFileContent($downloadUrl, $token);
                $schemas[$itemPath] = $fileContent;
            } elseif ($type === 'dir') {
                $subSchemas = $this->fetchDirectoryContents(
                    owner: $owner,
                    repo: $repo,
                    path: $itemPath,
                    ref: $ref,
                    token: $token
                );
                $schemas = array_merge($schemas, $subSchemas);
            }
        }

        return $schemas;
    }

    private function isYamlFile(string $filename): bool
    {
        return str_ends_with($filename, '.yaml') || str_ends_with($filename, '.yml');
    }

    private function fetchFileContent(string $url, ?string $token): string
    {
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('User-Agent', 'BehatOpenApiValidator');

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new SchemaLoaderException(
                \sprintf(
                    'Failed to fetch file from "%s" (%s): %s',
                    $url,
                    $token !== null && $token !== '' ? 'with authentication' : 'without authentication',
                    $e->getMessage(),
                ),
                previous: $e
            );
        }

        $this->validateResponse($response, $url);

        return $response->getBody()->getContents();
    }

    private function validateResponse(ResponseInterface $response, string $url): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        if ($statusCode === 403 && $response->getHeaderLine('X-RateLimit-Remaining') === '0') {
            $resetTime = $response->getHeaderLine('X-RateLimit-Reset');
            $message = \sprintf('GitHub API rate limit exceeded for "%s"', $url);

            if ($resetTime !== '') {
                $message .= \sprintf('. Reset at: %s', date('Y-m-d H:i:s', (int) $resetTime));
            }

            throw new SchemaLoaderException($message);
        }

        throw new SchemaLoaderException(
            \sprintf(
                'GitHub API request failed for "%s": HTTP %d %s',
                $url,
                $statusCode,
                $response->getReasonPhrase()
            )
        );
    }

    /**
     * @return non-empty-list<array{name: string, path: string, type: string, download_url: string|null}>
     */
    private function decodeJsonResponse(ResponseInterface $response, string $url): array
    {
        $body = $response->getBody()->getContents();

        try {
            /** @var list<array{name: string, path: string, type: string, download_url: string|null}> $contents */
            $contents = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SchemaLoaderException(
                \sprintf('Invalid JSON response from "%s": %s', $url, $e->getMessage()),
                previous: $e
            );
        }

        if ($contents === []) {
            throw new SchemaLoaderException(\sprintf('GitHub API returned invalid response for "%s"', $url));
        }

        return $contents;
    }
}
