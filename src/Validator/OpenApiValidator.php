<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\Validator;

use BehatOpenApiValidator\SchemaLoader\SchemaLoaderInterface;
use League\OpenAPIValidation\PSR7\Exception\NoPath;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use League\OpenAPIValidation\Schema\Exception\KeywordMismatch;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

use function strtolower;

class OpenApiValidator
{
    /** @var array<string, ServerRequestValidator> */
    private array $requestValidators = [];

    /** @var array<string, ResponseValidator> */
    private array $responseValidators = [];

    /** @var array<string, string> */
    private array $schemas = [];

    private bool $isInitialized = false;

    private readonly PsrHttpFactory $psrHttpFactory;

    public function __construct(private readonly SchemaLoaderInterface $schemaLoader)
    {
        $psr17Factory = new Psr17Factory();
        $this->psrHttpFactory = new PsrHttpFactory(
            serverRequestFactory: $psr17Factory,
            streamFactory: $psr17Factory,
            uploadedFileFactory: $psr17Factory,
            responseFactory: $psr17Factory
        );
    }

    private function initialize(): void
    {
        if ($this->isInitialized) {
            return;
        }

        $this->schemas = $this->schemaLoader->loadSchemas();

        foreach ($this->schemas as $path => $yamlContent) {
            $builder = (new ValidatorBuilder())->fromYaml($yamlContent);
            $this->requestValidators[$path] = $builder->getServerRequestValidator();
            $this->responseValidators[$path] = $builder->getResponseValidator();
        }

        $this->isInitialized = true;
    }

    public function validateRequest(Request $symfonyRequest): ValidationResult
    {
        $this->initialize();

        $psrRequest = $this->convertRequest($symfonyRequest);

        foreach ($this->requestValidators as $schemaPath => $validator) {
            try {
                $operationAddress = $validator->validate($psrRequest);

                return new ValidationResult(true, null, $operationAddress, $schemaPath);
            } catch (NoPath) {
                continue;
            } catch (ValidationFailed $exception) {
                $errorMessage = $this->buildDetailedErrorMessage($exception);
                return new ValidationResult(false, $errorMessage, null, $schemaPath);
            }
        }

        // No matching path found - all endpoints must be documented
        return new ValidationResult(
            false,
            \sprintf(
                'No OpenAPI schema found for endpoint: %s %s',
                $psrRequest->getMethod(),
                $psrRequest->getUri()->getPath()
            ),
            null,
            null
        );
    }

    public function validateResponse(
        Request $symfonyRequest,
        Response $symfonyResponse,
        ?OperationAddress $operationAddress = null,
        ?string $schemaPath = null
    ): ValidationResult {
        $this->initialize();

        $psrResponse = $this->convertResponse($symfonyResponse);
        $psrRequest = $this->convertRequest($symfonyRequest);

        // If we have a specific operation address from request validation, use it
        if ($operationAddress !== null && $schemaPath !== null && isset($this->responseValidators[$schemaPath])) {
            return $this->validateResponseWithValidator(
                $this->responseValidators[$schemaPath],
                $operationAddress,
                $psrResponse,
                $schemaPath
            );
        }

        // If we have a schema path but no operation address (request was invalid),
        // try to find the matching operation by path pattern matching
        if ($schemaPath !== null && isset($this->responseValidators[$schemaPath])) {
            $foundOperation = $this->findOperationForPath($schemaPath, $psrRequest);

            if ($foundOperation !== null) {
                return $this->validateResponseWithValidator(
                    $this->responseValidators[$schemaPath],
                    $foundOperation,
                    $psrResponse,
                    $schemaPath
                );
            }
        }

        // Otherwise try to find matching schema (path only, ignore request validation)
        foreach ($this->requestValidators as $path => $requestValidator) {
            $foundOperation = $this->findOperationForPath($path, $psrRequest);

            if ($foundOperation === null) {
                continue;
            }

            return $this->validateResponseWithValidator(
                $this->responseValidators[$path],
                $foundOperation,
                $psrResponse,
                $path
            );
        }

        // No matching path found - all endpoints must be documented
        return new ValidationResult(
            false,
            \sprintf(
                'No OpenAPI schema found for endpoint: %s %s',
                $psrRequest->getMethod(),
                $psrRequest->getUri()->getPath()
            ),
            null,
            null
        );
    }

    /**
     * Find the operation address for a given request path by pattern matching.
     * This doesn't validate the request body, only matches the path.
     */
    private function findOperationForPath(string $schemaPath, ServerRequestInterface $psrRequest): ?OperationAddress
    {
        if (\array_key_exists($schemaPath, $this->schemas) === false) {
            return null;
        }

        $yamlContent = $this->schemas[$schemaPath];
        $spec = Yaml::parse($yamlContent);

        if (!\is_array($spec) || !isset($spec['paths']) || !\is_array($spec['paths'])) {
            return null;
        }

        $requestPath = $psrRequest->getUri()->getPath();
        $requestMethod = strtolower($psrRequest->getMethod());

        foreach ($spec['paths'] as $specPath => $pathItem) {
            if (\is_array($pathItem) === false || \array_key_exists($requestMethod, $pathItem) === false) {
                continue;
            }

            if (OperationAddress::isPathMatchesSpec($specPath, $requestPath)) {
                return new OperationAddress($specPath, $requestMethod);
            }
        }

        return null;
    }

    private function validateResponseWithValidator(
        ResponseValidator $validator,
        OperationAddress $operationAddress,
        ResponseInterface $response,
        string $schemaPath
    ): ValidationResult {
        try {
            $validator->validate($operationAddress, $response);

            return new ValidationResult(true, null, $operationAddress, $schemaPath);
        } catch (ValidationFailed $exception) {
            $errorMessage = $this->buildDetailedErrorMessage($exception);

            return new ValidationResult(false, $errorMessage, $operationAddress, $schemaPath);
        }
    }

    private function buildDetailedErrorMessage(ValidationFailed $exception): string
    {
        $currentException = $exception;
        $messages = [$exception->getMessage()];

        // Traverse the exception chain to collect all error messages
        while ($currentException = $currentException->getPrevious()) {
            $messages[] = $currentException->getMessage();

            // Add field path information for schema mismatches
            if (!$currentException instanceof KeywordMismatch) {
                continue;
            }

            $breadCrumb = $currentException->dataBreadCrumb();

            if ($breadCrumb === null) {
                continue;
            }

            $messages[] = \sprintf('Field: %s', implode('.', $breadCrumb->buildChain()));
        }

        return implode(' | ', array_reverse($messages));
    }

    private function convertRequest(Request $symfonyRequest): ServerRequestInterface
    {
        return $this->psrHttpFactory->createRequest($symfonyRequest);
    }

    private function convertResponse(Response $symfonyResponse): ResponseInterface
    {
        return $this->psrHttpFactory->createResponse($symfonyResponse);
    }
}
