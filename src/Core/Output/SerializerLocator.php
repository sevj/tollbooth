<?php

declare(strict_types=1);

namespace Tollbooth\Core\Output;

use Psr\Container\ContainerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * @internal
 */
final readonly class SerializerLocator
{
    public function __construct(
        private ContainerInterface $serializers,
    ) {
    }

    public function for(NameCase $case): SerializerInterface&NormalizerInterface&DenormalizerInterface
    {
        $serializer = $this->serializers->get($case->value);
        if (!$serializer instanceof SerializerInterface || !$serializer instanceof NormalizerInterface || !$serializer instanceof DenormalizerInterface) {
            throw new \LogicException(\sprintf('The Tollbooth serializer for the "%s" case cannot normalize and denormalize.', $case->value));
        }

        return $serializer;
    }
}
