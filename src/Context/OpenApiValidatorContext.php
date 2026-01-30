<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use BehatOpenApiValidator\EventListener\RequestResponseHolder;
use BehatOpenApiValidator\Validator\OpenApiValidator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OpenApiValidatorContext implements Context
{
    private bool $shouldRequestBeSkipped = false;
    private bool $shouldResponseBeSkipped = false;

    public function __construct(
        private readonly OpenApiValidator $validator,
        private readonly bool $isEnabled = true,
        private readonly bool $shouldRequestOn4xxBeSkipped = true
    ) {}

    /**
     * @BeforeScenario
     */
    public function beforeScenario(BeforeScenarioScope $scope): void
    {
        $this->shouldRequestBeSkipped = false;
        $this->shouldResponseBeSkipped = false;
        RequestResponseHolder::reset();

        $tags = array_merge(
            $scope->getFeature()->getTags(),
            $scope->getScenario()->getTags()
        );

        if (\in_array('skipOpenApiValidation', $tags, true)) {
            $this->shouldRequestBeSkipped = true;
            $this->shouldResponseBeSkipped = true;
        }

        if (\in_array('skipOpenApiRequestValidation', $tags, true)) {
            $this->shouldRequestBeSkipped = true;
        }

        if (\in_array('skipOpenApiResponseValidation', $tags, true)) {
            $this->shouldResponseBeSkipped = true;
        }
    }

    /**
     * @Given OpenAPI validation is disabled
     */
    public function openApiValidationIsDisabled(): void
    {
        $this->shouldRequestBeSkipped = true;
        $this->shouldResponseBeSkipped = true;
    }

    /**
     * @Given OpenAPI request validation is disabled
     */
    public function openApiRequestValidationIsDisabled(): void
    {
        $this->shouldRequestBeSkipped = true;
    }

    /**
     * @Given OpenAPI response validation is disabled
     */
    public function openApiResponseValidationIsDisabled(): void
    {
        $this->shouldResponseBeSkipped = true;
    }

    /**
     * @AfterStep
     */
    public function afterStep(AfterStepScope $scope): void
    {
        if ($this->isEnabled === false) {
            return;
        }

        $stepText = $scope->getStep()->getText();

        // Only validate after request steps
        if ($this->isRequestStep($stepText) === false) {
            return;
        }

        // Get request/response from static holder (captured by event subscriber)
        $request = RequestResponseHolder::getLastRequest();
        $response = RequestResponseHolder::getLastResponse();

        if ($request === null || $response === null) {
            throw new RuntimeException(\sprintf(
                'OpenAPI validation failed: No request/response captured for step "%s". '
                . 'Request: %s, Response: %s. '
                . 'Ensure the ApiContextListener is registered as a kernel event subscriber.',
                $stepText,
                $request === null ? 'null' : 'captured',
                $response === null ? 'null' : 'captured'
            ));
        }

        $this->validateRequestResponse($request, $response);

        // Reset for next request
        RequestResponseHolder::reset();
    }

    private function validateRequestResponse(Request $request, Response $response): void
    {
        $statusCode = $response->getStatusCode();
        $is4xxResponse = $statusCode >= 400 && $statusCode < 500;

        // Determine if we should skip request validation
        $shouldSkipRequest = $this->shouldRequestBeSkipped || ($is4xxResponse && $this->shouldRequestOn4xxBeSkipped);

        // Validate request (always run to get operation address for response validation)
        $requestResult = $this->validator->validateRequest($request);
        $operationAddress = $requestResult->getOperationAddress();
        $schemaPath = $requestResult->getSchemaPath();

        // Throw if request validation failed and not skipped
        if ($shouldSkipRequest === false && $requestResult->isValid() === false) {
            throw new RuntimeException(\sprintf(
                "OpenAPI request validation failed:\n%s\nSchema: %s",
                $requestResult->getErrorMessage(),
                $requestResult->getSchemaPath() ?? 'unknown'
            ));
        }

        if ($this->shouldResponseBeSkipped) {
            return;
        }

        // Validate response
        $responseResult = $this->validator->validateResponse(
            $request,
            $response,
            $operationAddress,
            $schemaPath
        );

        if ($responseResult->isValid()) {
            return;
        }

        throw new RuntimeException(\sprintf(
            "OpenAPI response validation failed:\n%s\nSchema: %s",
            $responseResult->getErrorMessage(),
            $responseResult->getSchemaPath() ?? 'unknown'
        ));
    }

    private function isRequestStep(string $stepText): bool
    {
        // Match common API request step patterns
        $patterns = [
            '/I send .+ request to .+ route/i',
            '/I send a .+ request to/i',
            '/I request /i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $stepText)) {
                return true;
            }
        }

        return false;
    }
}
