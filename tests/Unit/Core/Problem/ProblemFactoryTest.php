<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Core\Problem;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Tollbooth\Core\Problem\ProblemFactory;

final class ProblemFactoryTest extends TestCase
{
    public function testValidationFailureListsViolationsWithoutDetail(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('Must not be blank.', null, [], null, 'name', ''),
            new ConstraintViolation('Must be positive.', null, [], null, 'items[0].quantity', -1),
        ]);
        $exception = new HttpException(422, 'Validation failed.', new ValidationFailedException(null, $violations));

        $problem = (new ProblemFactory())->fromThrowable($exception)->toArray();

        self::assertSame([
            'type' => 'about:blank',
            'title' => 'Validation Failed',
            'status' => 422,
            'violations' => [
                ['field' => 'name', 'message' => 'Must not be blank.'],
                ['field' => 'items[0].quantity', 'message' => 'Must be positive.'],
            ],
        ], $problem);
    }

    public function testHttpExceptionHeadersAreKept(): void
    {
        $problem = (new ProblemFactory())->fromThrowable(new TooManyRequestsHttpException(8, 'Slow down.'));

        self::assertSame(429, $problem->status);
        self::assertSame('Slow down.', $problem->detail);
        self::assertSame(['Retry-After' => '8'], $problem->headers);
    }

    public function testServerErrorMessageOnlyShowsInDebug(): void
    {
        $exception = new \RuntimeException('Database password is hunter2.');

        self::assertNull((new ProblemFactory(debug: false))->fromThrowable($exception)->detail);
        self::assertSame('Database password is hunter2.', (new ProblemFactory(debug: true))->fromThrowable($exception)->detail);
    }
}
