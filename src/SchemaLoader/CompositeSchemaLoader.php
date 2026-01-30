<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\SchemaLoader;

class CompositeSchemaLoader implements SchemaLoaderInterface
{
    /**
     * @param list<SchemaLoaderInterface> $loaders
     */
    public function __construct(private readonly array $loaders) {}

    public function loadSchemas(): array
    {
        $schemas = [];

        foreach ($this->loaders as $loader) {
            $schemas = array_merge($schemas, $loader->loadSchemas());
        }

        return $schemas;
    }
}
