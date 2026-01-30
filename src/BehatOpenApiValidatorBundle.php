<?php

declare(strict_types=1);

namespace BehatOpenApiValidator;

use BehatOpenApiValidator\Context\OpenApiValidatorContext;
use BehatOpenApiValidator\EventListener\ApiContextListener;
use BehatOpenApiValidator\SchemaLoader\CompositeSchemaLoader;
use BehatOpenApiValidator\SchemaLoader\GithubSchemaLoader;
use BehatOpenApiValidator\SchemaLoader\LocalSchemaLoader;
use BehatOpenApiValidator\Validator\OpenApiValidator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class BehatOpenApiValidatorBundle extends AbstractBundle
{
    protected string $extensionAlias = 'behat_openapi_validator';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('is_enabled')
                    ->defaultTrue()
                ->end()
                ->booleanNode('should_request_on_4xx_be_skipped')
                    ->defaultTrue()
                ->end()
                ->arrayNode('local_paths')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('github_sources')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('url')->isRequired()->end()
                            ->scalarNode('token_env')->defaultNull()->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        // Register PSR-17 factory if not already available
        if (!$builder->has(RequestFactoryInterface::class)) {
            $services->set('behat_openapi_validator.psr17_factory', Psr17Factory::class);
            $services->alias(RequestFactoryInterface::class, 'behat_openapi_validator.psr17_factory');
        }

        // Local schema loader
        $services->set('behat_openapi_validator.schema_loader.local', LocalSchemaLoader::class)
            ->arg('$paths', $config['local_paths']);

        $loaders = [new Reference('behat_openapi_validator.schema_loader.local')];

        // GitHub schema loader - only if sources configured and HTTP client available
        $areGithubSourcesConfigured = \array_key_exists('github_sources', $config)
            && \is_array($config['github_sources'])
            && $config['github_sources'] !== [];

        if ($areGithubSourcesConfigured) {
            // Auto-register PSR-18 client if not already available
            if ($builder->has(ClientInterface::class) === false && class_exists(Psr18Client::class)) {
                // Psr18Client works standalone without constructor args
                $services->set('behat_openapi_validator.psr18_client', Psr18Client::class);
                $services->alias(ClientInterface::class, 'behat_openapi_validator.psr18_client');
            }

            $services->set('behat_openapi_validator.schema_loader.github', GithubSchemaLoader::class)
                ->arg('$sources', $config['github_sources'])
                ->arg('$httpClient', new Reference(ClientInterface::class))
                ->arg('$requestFactory', new Reference(RequestFactoryInterface::class));

            $loaders[] = new Reference('behat_openapi_validator.schema_loader.github');
        }

        // Composite schema loader
        $services->set('behat_openapi_validator.schema_loader', CompositeSchemaLoader::class)
            ->arg('$loaders', $loaders);

        // Validator
        $services->set('behat_openapi_validator.validator', OpenApiValidator::class)
            ->arg('$schemaLoader', new Reference('behat_openapi_validator.schema_loader'))
            ->public();
        $services->alias(OpenApiValidator::class, 'behat_openapi_validator.validator')
            ->public();

        // Context
        $services->set('behat_openapi_validator.context', OpenApiValidatorContext::class)
            ->arg('$validator', new Reference('behat_openapi_validator.validator'))
            ->arg('$isEnabled', $config['is_enabled'])
            ->arg('$shouldRequestOn4xxBeSkipped', $config['should_request_on_4xx_be_skipped'])
            ->public()
            ->autowire();
        $services->alias(OpenApiValidatorContext::class, 'behat_openapi_validator.context')
            ->public();

        // Subscriber to capture request/response
        $services->set('behat_openapi_validator.subscriber', ApiContextListener::class)
            ->tag('kernel.event_subscriber')
            ->public();
        $services->alias(ApiContextListener::class, 'behat_openapi_validator.subscriber');
    }
}
