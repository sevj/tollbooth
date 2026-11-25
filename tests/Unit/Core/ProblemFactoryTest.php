<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Tollbooth\Core\Problem\ProblemFactory;

final class ProblemFactoryTest extends TestCase
{
    public function testSecurityAndValidationExceptionsKeepTheirMeaning(): void
    {
        $problems = new ProblemFactory();
        $violations = new ConstraintViolationList([new ConstraintViolation('This value should not be blank.', null, [], null, 'name', '')]);

        $invalid = $problems->fromThrowable(new ValidationFailedException(null, $violations));
        self::assertSame([422, 'Validation Failed'], [$invalid->status, $invalid->title]);
        self::assertSame(403, $problems->fromThrowable(new AccessDeniedException())->status);

        $unauthenticated = $problems->fromThrowable(new BadCredentialsException());
        self::assertSame([401, ['WWW-Authenticate' => 'Session']], [$unauthenticated->status, $unauthenticated->headers]);
        self::assertSame(500, $problems->fromThrowable(new \RuntimeException('boom'))->status);
    }
}
