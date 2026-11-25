<?php

declare(strict_types=1);

namespace Tollbooth\Core\Output;

use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
final readonly class OutputRenderer
{
    public const int JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private SerializerLocator $serializers,
        private ResourceTypeResolver $types,
    ) {
    }

    public function render(mixed $result, int $status, Envelope $envelope, NameCase $case): Response
    {
        if (null === $result) {
            return Response::HTTP_OK === $status
                ? new Response(null, Response::HTTP_NO_CONTENT)
                : new Response(null, $status, ['Content-Type' => $envelope->contentType()]);
        }

        $normalized = $this->serializers->for($case)->normalize($result, 'json');
        $document = match ($envelope) {
            Envelope::Plain => $normalized,
            Envelope::Data => ['data' => $normalized],
            Envelope::JsonApi => $this->jsonApi($result, $normalized, $case),
        };

        return new Response(
            json_encode($document, self::JSON_FLAGS | \JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => $envelope->contentType()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonApi(mixed $result, mixed $normalized, NameCase $case): array
    {
        if (\is_object($result) && $this->isResource($normalized)) {
            return ['data' => $this->resource($result, $normalized, $case)];
        }

        if (\is_array($result) && array_is_list($result) && \is_array($normalized) && $this->isCollection($result, $normalized)) {
            $data = [];
            foreach ($result as $index => $item) {
                \assert(\is_object($item) && \is_array($normalized[$index]));
                $data[] = $this->resource($item, $normalized[$index], $case);
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
    private function resource(object $result, array $normalized, NameCase $case): array
    {
        $id = $normalized['id'];
        unset($normalized['id']);

        return [
            'type' => $this->types->typeOf($result, $case),
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
