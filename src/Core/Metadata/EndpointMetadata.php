<?php

declare(strict_types=1);

namespace Tollbooth\Core\Metadata;

/**
 * @internal
 *
 * @phpstan-import-type LimitArray from Limit
 *
 * @phpstan-type EndpointArray array{name: string, method: string, path: string, status: int, serviceId: string, class: class-string, file: string, requirements: array<string, string>, group: string, envelope: string, case: string, auth: list<string>, scopes: list<string>, limits: list<LimitArray>}
 */
final readonly class EndpointMetadata
{
    /**
     * @param class-string          $class
     * @param array<string, string> $requirements
     * @param list<string>          $auth
     * @param list<string>          $scopes
     * @param list<LimitArray>      $limits
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
        public string $group = Group::DEFAULT,
        public string $envelope = 'plain',
        public string $case = 'preserve',
        public array $auth = ['anonymous'],
        public array $scopes = [],
        public array $limits = [],
    ) {
    }

    public function routeName(): string
    {
        return Group::DEFAULT === $this->group ? 'tollbooth_'.$this->name : 'tollbooth_'.$this->group.'_'.$this->name;
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
            'group' => $this->group,
            'envelope' => $this->envelope,
            'case' => $this->case,
            'auth' => $this->auth,
            'scopes' => $this->scopes,
            'limits' => $this->limits,
        ];
    }

    /**
     * @param EndpointArray $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
