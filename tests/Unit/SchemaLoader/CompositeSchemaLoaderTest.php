<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\Tests\Unit\SchemaLoader;

use BehatOpenApiValidator\SchemaLoader\CompositeSchemaLoader;
use BehatOpenApiValidator\SchemaLoader\SchemaLoaderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompositeSchemaLoaderTest extends TestCase
{
    #[Test]
    public function itReturnsEmptyArrayWhenNoLoadersProvided(): void
    {
        $loader = new CompositeSchemaLoader([]);

        self::assertSame([], $loader->loadSchemas());
    }

    #[Test]
    public function itMergesSchemasFromMultipleLoaders(): void
    {
        $loader1 = $this->createMock(SchemaLoaderInterface::class);
        $loader1->method('loadSchemas')->willReturn([
            '/path/to/api1.yaml' => 'content1',
        ]);

        $loader2 = $this->createMock(SchemaLoaderInterface::class);
        $loader2->method('loadSchemas')->willReturn([
            '/path/to/api2.yaml' => 'content2',
        ]);

        $compositeLoader = new CompositeSchemaLoader([$loader1, $loader2]);
        $schemas = $compositeLoader->loadSchemas();

        self::assertCount(2, $schemas);
        self::assertSame('content1', $schemas['/path/to/api1.yaml']);
        self::assertSame('content2', $schemas['/path/to/api2.yaml']);
    }

    #[Test]
    public function itOverwritesDuplicateKeysWithLaterLoader(): void
    {
        $loader1 = $this->createMock(SchemaLoaderInterface::class);
        $loader1->method('loadSchemas')->willReturn([
            '/path/to/api.yaml' => 'content1',
        ]);

        $loader2 = $this->createMock(SchemaLoaderInterface::class);
        $loader2->method('loadSchemas')->willReturn([
            '/path/to/api.yaml' => 'content2',
        ]);

        $compositeLoader = new CompositeSchemaLoader([$loader1, $loader2]);
        $schemas = $compositeLoader->loadSchemas();

        self::assertCount(1, $schemas);
        self::assertSame('content2', $schemas['/path/to/api.yaml']);
    }
}
