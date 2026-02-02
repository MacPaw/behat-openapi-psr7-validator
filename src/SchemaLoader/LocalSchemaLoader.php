<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\SchemaLoader;

use Symfony\Component\Finder\Finder;

class LocalSchemaLoader implements SchemaLoaderInterface
{
    /**
     * @param list<string> $paths
     */
    public function __construct(protected readonly array $paths) {}

    public function loadSchemas(): array
    {
        $schemas = [];

        foreach ($this->paths as $path) {
            if (is_dir($path) === false) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($path)->name('*.yaml')->name('*.yml');

            foreach ($finder as $file) {
                $content = $file->getContents();
                $schemas[$file->getRealPath()] = $content;
            }
        }

        return $schemas;
    }
}
