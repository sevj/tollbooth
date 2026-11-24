<?php

declare(strict_types=1);

namespace Tollbooth\Core\Metadata;

/**
 * @internal
 *
 * @phpstan-type EndpointArray array{name: string, method: string, path: string, status: int, serviceId: string, class: class-string, file: string, requirements: array<string, string>}
 */
final readonly class EndpointMetadata
{
    /**
     * @param class-string          $class
     * @param array<string, string> $requirements
     */
    public function __construct(
        public string $name,
        public string $method,
        public string $path,
        public int $status,
        public string $serviceId,
        public string $class,
        public string $file,
        public array $requirements = [],
    ) {
    }

    /**
     * @return EndpointArray
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'method' => $this->method,
            'path' => $this->path,
            'status' => $this->status,
            'serviceId' => $this->serviceId,
            'class' => $this->class,
            'file' => $this->file,
            'requirements' => $this->requirements,
        ];
    }

    /**
     * @param EndpointArray $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['method'],
            $data['path'],
            $data['status'],
            $data['serviceId'],
            $data['class'],
            $data['file'],
            $data['requirements'],
        );
    }
}
