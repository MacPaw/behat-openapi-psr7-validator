<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\Tests\Unit\Validator;

use BehatOpenApiValidator\SchemaLoader\SchemaLoaderInterface;
use BehatOpenApiValidator\Validator\OpenApiValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OpenApiValidatorTest extends TestCase
{
    private const SIMPLE_OPENAPI_SCHEMA = <<<'YAML'
        openapi: 3.0.0
        info:
          title: Test API
          version: 1.0.0
        paths:
          /users:
            get:
              responses:
                '200':
                  description: Success
                  content:
                    application/json:
                      schema:
                        type: array
                        items:
                          type: object
                          properties:
                            id:
                              type: integer
                            name:
                              type: string
            post:
              requestBody:
                required: true
                content:
                  application/json:
                    schema:
                      type: object
                      required:
                        - name
                      properties:
                        name:
                          type: string
              responses:
                '201':
                  description: Created
                  content:
                    application/json:
                      schema:
                        type: object
                        properties:
                          id:
                            type: integer
                          name:
                            type: string
          /users/{id}:
            get:
              parameters:
                - name: id
                  in: path
                  required: true
                  schema:
                    type: integer
              responses:
                '200':
                  description: Success
                  content:
                    application/json:
                      schema:
                        type: object
                        properties:
                          id:
                            type: integer
                          name:
                            type: string
        YAML;

    #[Test]
    public function itValidatesValidGetRequest(): void
    {
        $schemaLoader = $this->createMockSchemaLoader(['api.yaml' => self::SIMPLE_OPENAPI_SCHEMA]);
        $validator = new OpenApiValidator($schemaLoader);

        $request = Request::create('/users', 'GET');
        $result = $validator->validateRequest($request);

        self::assertTrue($result->isValid());
        self::assertNull($result->getErrorMessage());
        self::assertNotNull($result->getOperationAddress());
        self::assertSame('api.yaml', $result->getSchemaPath());
    }

    #[Test]
    public function itValidatesValidPostRequest(): void
    {
        $schemaLoader = $this->createMockSchemaLoader(['api.yaml' => self::SIMPLE_OPENAPI_SCHEMA]);
        $validator = new OpenApiValidator($schemaLoader);

        $request = Request::create(
            '/users',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"name":"John Doe"}'
        );
        $result = $validator->validateRequest($request);

        self::assertTrue($result->isValid());
        self::assertNull($result->getErrorMessage());
    }

    #[Test]
    public function itFailsValidationForInvalidPostRequestBody(): void
    {
        $schemaLoader = $this->createMockSchemaLoader(['api.yaml' => self::SIMPLE_OPENAPI_SCHEMA]);
        $validator = new OpenApiValidator($schemaLoader);

        $request = Request::create(
            '/users',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}'
        );
        $result = $validator->validateRequest($request);

        self::assertFalse($result->isValid());
        $errorMessage = $result->getErrorMessage();
        self::assertNotNull($errorMessage);
        self::assertStringContainsString('name', strtolower($errorMessage));
    }

    #[Test]
    public function itFailsValidationForUndocumentedEndpoint(): void
    {
        $schemaLoader = $this->createMockSchemaLoader(['api.yaml' => self::SIMPLE_OPENAPI_SCHEMA]);
        $validator = new OpenApiValidator($schemaLoader);

        $request = Request::create('/undocumented', 'GET');
        $result = $validator->validateRequest($request);

        self::assertFalse($result->isValid());
        $errorMessage = $result->getErrorMessage();
        self::assertNotNull($errorMessage);
        self::assertStringContainsString('No OpenAPI schema found', $errorMessage);
    }

    #[Test]
    public function itValidatesValidResponse(): void
    {
        $schemaLoader = $this->createMockSchemaLoader(['api.yaml' => self::SIMPLE_OPENAPI_SCHEMA]);
        $validator = new OpenApiValidator($schemaLoader);

        $request = Request::create('/users', 'GET');
        $requestResult = $validator->validateRequest($request);

        $response = new Response(
            '[{"id":1,"name":"John"}]',
            200,
            ['Content-Type' => 'application/json']
        );

        $result = $validator->validateResponse(
            $request,
            $response,
            $requestResult->getOperationAddress(),
            $requestResult->getSchemaPath()
        );

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function itFailsValidationForInvalidResponseBody(): void
    {
        $schemaLoader = $this->createMockSchemaLoader(['api.yaml' => self::SIMPLE_OPENAPI_SCHEMA]);
        $validator = new OpenApiValidator($schemaLoader);

        $request = Request::create('/users', 'GET');
        $requestResult = $validator->validateRequest($request);

        $response = new Response(
            '{"invalid":"structure"}',
            200,
            ['Content-Type' => 'application/json']
        );

        $result = $validator->validateResponse(
            $request,
            $response,
            $requestResult->getOperationAddress(),
            $requestResult->getSchemaPath()
        );

        self::assertFalse($result->isValid());
        self::assertNotNull($result->getErrorMessage());
    }

    #[Test]
    public function itValidatesPathParameters(): void
    {
        $schemaLoader = $this->createMockSchemaLoader(['api.yaml' => self::SIMPLE_OPENAPI_SCHEMA]);
        $validator = new OpenApiValidator($schemaLoader);

        $request = Request::create('/users/123', 'GET');
        $result = $validator->validateRequest($request);

        self::assertTrue($result->isValid());
        self::assertNotNull($result->getOperationAddress());
    }

    #[Test]
    public function itValidatesResponseWithoutOperationAddress(): void
    {
        $schemaLoader = $this->createMockSchemaLoader(['api.yaml' => self::SIMPLE_OPENAPI_SCHEMA]);
        $validator = new OpenApiValidator($schemaLoader);

        $request = Request::create('/users', 'GET');
        $response = new Response(
            '[{"id":1,"name":"John"}]',
            200,
            ['Content-Type' => 'application/json']
        );

        $result = $validator->validateResponse($request, $response);

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function itHandlesMultipleSchemas(): void
    {
        $schema1 = <<<'YAML'
            openapi: 3.0.0
            info:
              title: API v1
              version: 1.0.0
            paths:
              /v1/users:
                get:
                  responses:
                    '200':
                      description: Success
            YAML;

        $schema2 = <<<'YAML'
            openapi: 3.0.0
            info:
              title: API v2
              version: 2.0.0
            paths:
              /v2/users:
                get:
                  responses:
                    '200':
                      description: Success
            YAML;

        $schemaLoader = $this->createMockSchemaLoader([
            'v1/api.yaml' => $schema1,
            'v2/api.yaml' => $schema2,
        ]);
        $validator = new OpenApiValidator($schemaLoader);

        $request1 = Request::create('/v1/users', 'GET');
        $result1 = $validator->validateRequest($request1);
        self::assertTrue($result1->isValid());
        self::assertSame('v1/api.yaml', $result1->getSchemaPath());

        $request2 = Request::create('/v2/users', 'GET');
        $result2 = $validator->validateRequest($request2);
        self::assertTrue($result2->isValid());
        self::assertSame('v2/api.yaml', $result2->getSchemaPath());
    }

    /**
     * @param array<string, string> $schemas
     */
    private function createMockSchemaLoader(array $schemas): SchemaLoaderInterface
    {
        $loader = $this->createMock(SchemaLoaderInterface::class);
        $loader->method('loadSchemas')->willReturn($schemas);

        return $loader;
    }
}
