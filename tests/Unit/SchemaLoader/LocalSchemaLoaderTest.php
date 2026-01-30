<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\Tests\Unit\SchemaLoader;

use BehatOpenApiValidator\SchemaLoader\LocalSchemaLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocalSchemaLoaderTest extends TestCase
{
    #[Test]
    public function itReturnsEmptyArrayWhenNoPathsProvided(): void
    {
        $loader = new LocalSchemaLoader([]);

        self::assertSame([], $loader->loadSchemas());
    }

    #[Test]
    public function itSkipsNonExistentDirectories(): void
    {
        $loader = new LocalSchemaLoader(['/non/existent/path']);

        self::assertSame([], $loader->loadSchemas());
    }

    #[Test]
    public function itLoadsYamlFilesFromDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/behat-openapi-test-' . uniqid();
        mkdir($tempDir, 0o777, true);

        $yamlContent = "openapi: '3.0.0'\ninfo:\n  title: Test API\n  version: '1.0.0'";
        file_put_contents($tempDir . '/api.yaml', $yamlContent);
        file_put_contents($tempDir . '/api2.yml', $yamlContent);
        file_put_contents($tempDir . '/not-yaml.txt', 'ignored');

        try {
            $loader = new LocalSchemaLoader([$tempDir]);
            $schemas = $loader->loadSchemas();

            self::assertCount(2, $schemas);

            foreach ($schemas as $content) {
                self::assertSame($yamlContent, $content);
            }
        } finally {
            unlink($tempDir . '/api.yaml');
            unlink($tempDir . '/api2.yml');
            unlink($tempDir . '/not-yaml.txt');
            rmdir($tempDir);
        }
    }

    #[Test]
    public function itLoadsFromMultipleDirectories(): void
    {
        $tempDir1 = sys_get_temp_dir() . '/behat-openapi-test1-' . uniqid();
        $tempDir2 = sys_get_temp_dir() . '/behat-openapi-test2-' . uniqid();
        mkdir($tempDir1, 0o777, true);
        mkdir($tempDir2, 0o777, true);

        $yamlContent = "openapi: '3.0.0'";
        file_put_contents($tempDir1 . '/api1.yaml', $yamlContent);
        file_put_contents($tempDir2 . '/api2.yaml', $yamlContent);

        try {
            $loader = new LocalSchemaLoader([$tempDir1, $tempDir2]);
            $schemas = $loader->loadSchemas();

            self::assertCount(2, $schemas);
        } finally {
            unlink($tempDir1 . '/api1.yaml');
            unlink($tempDir2 . '/api2.yaml');
            rmdir($tempDir1);
            rmdir($tempDir2);
        }
    }
}
