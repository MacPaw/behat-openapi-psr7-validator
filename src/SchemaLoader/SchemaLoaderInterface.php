<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\SchemaLoader;

interface SchemaLoaderInterface
{
    /**
     * @return array<string, string> Map of operation path pattern to OpenAPI YAML content
     */
    public function loadSchemas(): array;
}
