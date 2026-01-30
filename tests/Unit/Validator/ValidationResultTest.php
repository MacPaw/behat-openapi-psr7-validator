<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\Tests\Unit\Validator;

use BehatOpenApiValidator\Validator\ValidationResult;
use League\OpenAPIValidation\PSR7\OperationAddress;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidationResultTest extends TestCase
{
    #[Test]
    public function itCreatesValidResult(): void
    {
        $operationAddress = new OperationAddress('/users', 'get');
        $result = new ValidationResult(true, null, $operationAddress, '/path/to/schema.yaml');

        self::assertTrue($result->isValid());
        self::assertNull($result->getErrorMessage());
        self::assertSame($operationAddress, $result->getOperationAddress());
        self::assertSame('/path/to/schema.yaml', $result->getSchemaPath());
    }

    #[Test]
    public function itCreatesInvalidResult(): void
    {
        $result = new ValidationResult(false, 'Validation failed', null, '/path/to/schema.yaml');

        self::assertFalse($result->isValid());
        self::assertSame('Validation failed', $result->getErrorMessage());
        self::assertNull($result->getOperationAddress());
        self::assertSame('/path/to/schema.yaml', $result->getSchemaPath());
    }

    #[Test]
    public function itHandlesNullOptionalValues(): void
    {
        $result = new ValidationResult(false, 'No schema found');

        self::assertFalse($result->isValid());
        self::assertSame('No schema found', $result->getErrorMessage());
        self::assertNull($result->getOperationAddress());
        self::assertNull($result->getSchemaPath());
    }
}
