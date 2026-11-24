<?php

declare(strict_types=1);

namespace Tollbooth\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Tollbooth\Core\Attribute\Body;

/**
 * @internal
 */
final readonly class BodyValueResolver implements ValueResolverInterface
{
    public function __construct(
        private SerializerInterface&DenormalizerInterface $serializer,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @return list<object|null>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        $attribute = $argument->getAttributesOfType(Body::class)[0] ?? null;
        if (!$attribute instanceof Body) {
            return [];
        }

        $type = (string) $argument->getType();
        if (!class_exists($type)) {
            throw new \LogicException(\sprintf('The #[Body] argument "$%s" must be typed with a class, "%s" given.', $argument->getName(), $type));
        }

        $content = $request->getContent();
        if ('' === trim($content) && $argument->isNullable()) {
            return [null];
        }

        $this->assertJson($request);
        $object = $this->deserialize('' === trim($content) ? '{}' : $content, $type);
        $violations = $this->validator->validate($object, null, $attribute->groups);
        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException('', new ValidationFailedException($object, $violations));
        }

        return [$object];
    }

    private function assertJson(Request $request): void
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        if ('' !== $contentType && !str_contains(strtolower($contentType), 'json')) {
            throw new UnsupportedMediaTypeHttpException(\sprintf('The request body must be JSON, "%s" given.', $contentType));
        }
    }

    /**
     * @param class-string $type
     */
    private function deserialize(string $content, string $type): object
    {
        try {
            $object = $this->serializer->deserialize($content, $type, 'json', [
                DenormalizerInterface::COLLECT_DENORMALIZATION_ERRORS => true,
            ]);
        } catch (PartialDenormalizationException $exception) {
            throw new UnprocessableEntityHttpException('', new ValidationFailedException($content, $this->typeViolations($exception)));
        } catch (UnexpectedValueException $exception) {
            throw new BadRequestHttpException('The request body is not valid JSON.', $exception);
        }

        return $object;
    }

    private function typeViolations(PartialDenormalizationException $exception): ConstraintViolationList
    {
        $violations = new ConstraintViolationList();
        foreach ($exception->getErrors() as $error) {
            if (!$error instanceof NotNormalizableValueException) {
                continue;
            }

            $expected = implode(' or ', $error->getExpectedTypes() ?? ['another type']);
            $message = \sprintf('This value should be of type %s.', $expected);
            $violations->add(new ConstraintViolation($message, $message, [], null, (string) $error->getPath(), null));
        }

        return $violations;
    }
}
