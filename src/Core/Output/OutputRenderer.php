<?php

declare(strict_types=1);

namespace Tollbooth\Core\Output;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\EncoderInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * @internal
 */
final readonly class OutputRenderer
{
    public const int JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private NormalizerInterface&EncoderInterface $serializer,
        private Envelope $envelope,
        private ResourceTypeResolver $types,
    ) {
    }

    public function render(mixed $result, int $status): Response
    {
        if (null === $result) {
            return new Response(null, Response::HTTP_OK === $status ? Response::HTTP_NO_CONTENT : $status);
        }

        $normalized = $this->serializer->normalize($result, 'json');
        $document = match ($this->envelope) {
            Envelope::Plain => $normalized,
            Envelope::Data => ['data' => $normalized],
            Envelope::JsonApi => $this->jsonApi($result, $normalized),
        };

        return new Response(
            json_encode($document, self::JSON_FLAGS | \JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => $this->envelope->contentType()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonApi(mixed $result, mixed $normalized): array
    {
        if (\is_object($result) && $this->isResource($normalized)) {
            return ['data' => $this->resource($result, $normalized)];
        }

        if (\is_array($result) && array_is_list($result) && \is_array($normalized) && $this->isCollection($result, $normalized)) {
            $data = [];
            foreach ($result as $index => $item) {
                \assert(\is_object($item) && \is_array($normalized[$index]));
                $data[] = $this->resource($item, $normalized[$index]);
            }

            return ['data' => $data];
        }

        return ['meta' => $normalized];
    }

    /**
     * @param array<mixed> $normalized
     *
     * @return array{type: string, id: string, attributes: array<mixed>}
     */
    private function resource(object $result, array $normalized): array
    {
        $id = $normalized['id'];
        unset($normalized['id']);

        return [
            'type' => $this->types->typeOf($result),
            'id' => \is_scalar($id) ? (string) $id : '',
            'attributes' => $normalized,
        ];
    }

    /**
     * @phpstan-assert-if-true array<mixed> $normalized
     */
    private function isResource(mixed $normalized): bool
    {
        return \is_array($normalized) && isset($normalized['id']) && \is_scalar($normalized['id']);
    }

    /**
     * @param list<mixed>  $result
     * @param array<mixed> $normalized
     */
    private function isCollection(array $result, array $normalized): bool
    {
        foreach ($result as $index => $item) {
            if (!\is_object($item) || !$this->isResource($normalized[$index] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
