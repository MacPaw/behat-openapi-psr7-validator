<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\Validator;

use League\OpenAPIValidation\PSR7\OperationAddress;

class ValidationResult
{
    public function __construct(
        private readonly bool $isValid,
        private readonly ?string $errorMessage = null,
        private readonly ?OperationAddress $operationAddress = null,
        private readonly ?string $schemaPath = null
    ) {}

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getOperationAddress(): ?OperationAddress
    {
        return $this->operationAddress;
    }

    public function getSchemaPath(): ?string
    {
        return $this->schemaPath;
    }
}
