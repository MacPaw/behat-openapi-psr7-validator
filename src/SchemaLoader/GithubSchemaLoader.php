<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\SchemaLoader;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

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
                continue;
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
            $contents = json_decode($response->getBody()->getContents(), true);
        } catch (ClientExceptionInterface) {
            return [];
        }

        if (\is_array($contents) === false) {
            return [];
        }

        $schemas = [];

        /** @var mixed $item */
        foreach ($contents as $item) {
            if (\is_array($item) === false) {
                continue;
            }

            /** @var array<string, mixed> $item */
            $type = $item['type'] ?? null;
            $name = $item['name'] ?? null;
            $itemPath = $item['path'] ?? null;
            $downloadUrl = $item['download_url'] ?? null;

            if (\is_string($type)  === false || \is_string($name) === false || \is_string($itemPath) === false) {
                continue;
            }

            if ($type === 'file' && $this->isYamlFile($name) && \is_string($downloadUrl)) {
                $fileContent = $this->fetchFileContent($downloadUrl, $token);
                if ($fileContent !== null) {
                    $schemas[$itemPath] = $fileContent;
                }
            } elseif ($type === 'dir') {
                $subSchemas = $this->fetchDirectoryContents($owner, $repo, $itemPath, $ref, $token);
                $schemas = array_merge($schemas, $subSchemas);
            }
        }

        return $schemas;
    }

    private function isYamlFile(string $filename): bool
    {
        return str_ends_with($filename, '.yaml') || str_ends_with($filename, '.yml');
    }

    private function fetchFileContent(string $url, ?string $token): ?string
    {
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('User-Agent', 'BehatOpenApiValidator');

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        try {
            $response = $this->httpClient->sendRequest($request);

            return $response->getBody()->getContents();
        } catch (ClientExceptionInterface) {
            return null;
        }
    }
}
