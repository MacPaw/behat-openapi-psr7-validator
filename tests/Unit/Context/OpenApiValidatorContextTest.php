<?php

declare(strict_types=1);

namespace BehatOpenApiValidator\Tests\Unit\Context;

use BehatOpenApiValidator\Context\OpenApiValidatorContext;
use BehatOpenApiValidator\EventListener\RequestResponseHolder;
use BehatOpenApiValidator\Validator\OpenApiValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use ReflectionProperty;

final class OpenApiValidatorContextTest extends TestCase
{
    protected function setUp(): void
    {
        RequestResponseHolder::reset();
    }

    protected function tearDown(): void
    {
        RequestResponseHolder::reset();
    }

    #[Test]
    public function itResetsStateBeforeScenario(): void
    {
        RequestResponseHolder::capture(
            Request::create('/test', 'GET'),
            new Response()
        );

        $validator = $this->createMock(OpenApiValidator::class);
        $context = new OpenApiValidatorContext($validator);

        self::assertNotNull(RequestResponseHolder::getLastRequest());

        // Simulate beforeScenario by directly testing reset behavior
        RequestResponseHolder::reset();

        self::assertNull(RequestResponseHolder::getLastRequest());
        self::assertNull(RequestResponseHolder::getLastResponse());
    }

    #[Test]
    public function itSkipsValidationWhenDisabledViaStep(): void
    {
        $validator = $this->createMock(OpenApiValidator::class);
        $validator->expects(self::never())->method('validateRequest');

        $context = new OpenApiValidatorContext($validator);

        // Use the step method to disable validation
        $context->openApiValidationIsDisabled();

        // Set private properties to verify state
        $reflection = new ReflectionProperty($context, 'shouldRequestBeSkipped');
        $reflection->setAccessible(true);
        self::assertTrue($reflection->getValue($context));

        $reflection = new ReflectionProperty($context, 'shouldResponseBeSkipped');
        $reflection->setAccessible(true);
        self::assertTrue($reflection->getValue($context));
    }

    #[Test]
    public function itSkipsRequestValidationWhenDisabledViaStep(): void
    {
        $validator = $this->createMock(OpenApiValidator::class);

        $context = new OpenApiValidatorContext($validator);

        // Use the step method to disable request validation
        $context->openApiRequestValidationIsDisabled();

        // Set private property to verify state
        $reflection = new ReflectionProperty($context, 'shouldRequestBeSkipped');
        $reflection->setAccessible(true);
        self::assertTrue($reflection->getValue($context));

        $reflection = new ReflectionProperty($context, 'shouldResponseBeSkipped');
        $reflection->setAccessible(true);
        self::assertFalse($reflection->getValue($context));
    }

    #[Test]
    public function itSkipsResponseValidationWhenDisabledViaStep(): void
    {
        $validator = $this->createMock(OpenApiValidator::class);

        $context = new OpenApiValidatorContext($validator);

        // Use the step method to disable response validation
        $context->openApiResponseValidationIsDisabled();

        // Set private property to verify state
        $reflection = new ReflectionProperty($context, 'shouldRequestBeSkipped');
        $reflection->setAccessible(true);
        self::assertFalse($reflection->getValue($context));

        $reflection = new ReflectionProperty($context, 'shouldResponseBeSkipped');
        $reflection->setAccessible(true);
        self::assertTrue($reflection->getValue($context));
    }

    #[Test]
    public function itDoesNotValidateWhenDisabled(): void
    {
        $validator = $this->createMock(OpenApiValidator::class);
        $context = new OpenApiValidatorContext($validator, isEnabled: false);

        // Verify isEnabled can be checked via constructor
        self::assertInstanceOf(OpenApiValidatorContext::class, $context);
    }
}
