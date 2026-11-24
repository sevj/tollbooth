<?php

declare(strict_types=1);

namespace Tollbooth\Auth;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * @internal
 */
final class ConsumerValueResolver implements ValueResolverInterface
{
    /**
     * @return list<Consumer|null>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        if (Consumer::class !== $argument->getType()) {
            return [];
        }

        $consumer = $request->attributes->get(AccessListener::CONSUMER_ATTRIBUTE);
        if ($consumer instanceof Consumer) {
            return [$consumer];
        }

        if ($argument->isNullable()) {
            return [null];
        }

        throw new \LogicException(\sprintf('Argument "$%s" asks for the Tollbooth consumer, which is only known inside an endpoint.', $argument->getName()));
    }
}
