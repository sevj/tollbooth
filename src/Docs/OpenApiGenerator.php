<?php

declare(strict_types=1);

namespace Tollbooth\Docs;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Contracts\Cache\CacheInterface;
use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Query;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Core\Metadata\EndpointRegistry;
use Tollbooth\Core\Metadata\Group;
use Tollbooth\Core\Output\Envelope;
use Tollbooth\Core\Output\NameCase;
use Tollbooth\Core\Output\ResourceTypeResolver;
use Tollbooth\Core\Security\AuthMethod;

/**
 * @internal
 */
final class OpenApiGenerator
{
    private const array ERRORS = [
        400 => ['BadRequest', 'The request is malformed.', 'Problem'],
        401 => ['Unauthorized', 'Credentials are missing or rejected.', 'Problem'],
        403 => ['Forbidden', 'The consumer lacks a required scope.', 'Problem'],
        404 => ['NotFound', 'The resource does not exist.', 'Problem'],
        415 => ['UnsupportedMediaType', 'The body is not JSON.', 'Problem'],
        422 => ['ValidationFailed', 'The body does not pass validation.', 'ValidationProblem'],
        429 => ['TooManyRequests', 'A rate limit is reached.', 'RateLimitProblem'],
    ];

    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $documents = [];

    /**
     * @param array<string, string> $groupPrefixes
     */
    public function __construct(
        private readonly EndpointRegistry $registry,
        private readonly ResourceTypeResolver $resourceTypes,
        private readonly string $pathPrefix = '',
        private readonly string $title = 'API',
        private readonly string $version = '1.0.0',
        private readonly string $apiKeyHeader = 'X-Api-Key',
        private readonly bool $hasPlans = false,
        private readonly array $groupPrefixes = [],
        private readonly ?CacheInterface $cache = null,
        private readonly bool $etag = true,
        private readonly bool $limitsEnforced = true,
    ) {
    }

    /**
     * @return list<string>
     */
    public function groups(): array
    {
        $groups = array_values(array_unique(array_map(static fn (EndpointMetadata $endpoint): string => $endpoint->group, $this->registry->all())));
        usort($groups, static fn (string $a, string $b): int => [Group::DEFAULT !== $a, $a] <=> [Group::DEFAULT !== $b, $b]);

        return $groups;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function generate(string $group): ?array
    {
        if (!\array_key_exists($group, $this->documents)) {
            $cache = $this->cache;
            $this->documents[$group] = null === $cache
                ? $this->build($group)
                : $cache->get('tollbooth_openapi_'.hash('xxh128', $group), fn (): ?array => $this->build($group));
        }

        return $this->documents[$group];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build(string $group): ?array
    {
        $endpoints = array_values(array_filter($this->registry->all(), static fn (EndpointMetadata $endpoint): bool => $endpoint->group === $group));
        if ([] === $endpoints) {
            return null;
        }

        $schemas = new SchemaGenerator(NameCase::from($endpoints[0]->case));
        $paths = [];
        $errors = [];
        $schemes = [];
        foreach ($endpoints as $endpoint) {
            $operation = $this->operation($endpoint, $schemas, $errors, $schemes);
            $paths[$endpoint->path][strtolower($endpoint->method)] = $operation;
        }
        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => Group::DEFAULT === $group ? $this->title : $this->title.' · '.$group, 'version' => $this->version],
            'servers' => [['url' => '' === rtrim($this->pathPrefix, '/') ? '/' : rtrim($this->pathPrefix, '/')]],
            'paths' => $paths,
            'components' => array_filter([
                'schemas' => $schemas->components() + $this->problemSchemas($errors),
                'responses' => $this->errorResponses($errors),
                'headers' => $this->hasLimits($endpoints) ? $this->rateLimitHeaders() : [],
                'securitySchemes' => $this->securitySchemes($schemes),
            ], static fn (array $section): bool => [] !== $section),
        ];
    }

    /**
     * @param array<int, true>    $errors
     * @param array<string, true> $schemes
     *
     * @return array<string, mixed>
     */
    private function operation(EndpointMetadata $endpoint, SchemaGenerator $schemas, array &$errors, array &$schemes): array
    {
        $invoke = new \ReflectionMethod($endpoint->class, '__invoke');
        $operation = [
            'operationId' => $endpoint->routeName(),
            'summary' => $this->summary($endpoint->class),
            'tags' => [$this->tag($endpoint)],
        ];

        $parameters = [];
        $body = null;
        preg_match_all('/\{(\w+)\}/', $endpoint->path, $matches);
        foreach ($invoke->getParameters() as $parameter) {
            $query = $parameter->getAttributes(Query::class)[0] ?? null;
            if (null !== $query) {
                $parameters[] = $this->queryParameter($parameter, $query->newInstance(), $schemas);
            } elseif ([] !== $parameter->getAttributes(Body::class)) {
                $body = $parameter;
            } elseif (\in_array($parameter->getName(), $matches[1], true)) {
                $parameters[] = ['name' => $parameter->getName(), 'in' => 'path', 'required' => true, 'schema' => $schemas->forType($schemas->resolve($parameter))];
            }
        }

        foreach ($matches[1] as $placeholder) {
            if ([] === array_filter($parameters, static fn (array $parameter): bool => 'path' === $parameter['in'] && $placeholder === $parameter['name'])) {
                $parameters[] = ['name' => $placeholder, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
            }
        }

        if ([] !== $parameters) {
            $operation['parameters'] = $parameters;
        }

        if (null !== $body) {
            $operation['requestBody'] = [
                'required' => !$body->allowsNull(),
                'content' => ['application/json' => ['schema' => $schemas->forType($this->unwrapNullable($schemas->resolve($body)), true)]],
            ];
        }

        $auth = array_map(AuthMethod::from(...), $endpoint->auth);
        $limited = $this->limitsEnforced && ($this->hasPlans || [] !== $endpoint->limits);
        $statuses = array_keys(array_filter([
            400 => null !== $body || $this->hasQuery($invoke),
            401 => !\in_array(AuthMethod::Anonymous, $auth, true),
            403 => [] !== $endpoint->scopes,
            404 => [] !== $matches[1],
            415 => null !== $body,
            422 => null !== $body,
            429 => $limited,
        ]));

        $operation['responses'] = $this->success($endpoint, $invoke, $schemas, $limited);
        foreach ($statuses as $status) {
            $operation['responses'][(string) $status] = ['$ref' => '#/components/responses/'.self::ERRORS[$status][0]];
            $errors[$status] = true;
        }

        $security = $this->security($auth, $endpoint->scopes, $schemes);
        if (null !== $security) {
            $operation['security'] = $security;
        }

        if (null !== $endpoint->deprecation) {
            $operation['deprecated'] = true;
            $operation['x-deprecated-since'] = gmdate('Y-m-d', $endpoint->deprecation['since']);
            if (null !== $endpoint->deprecation['sunset']) {
                $operation['x-sunset'] = gmdate('Y-m-d', $endpoint->deprecation['sunset']);
            }
            if (null !== $endpoint->deprecation['link']) {
                $operation['externalDocs'] = ['description' => 'Migration guide', 'url' => $endpoint->deprecation['link']];
            }
        }

        if ($this->limitsEnforced && [] !== $endpoint->limits) {
            $operation['x-rate-limits'] = array_map(static fn (array $limit): array => ['name' => $limit['name'], 'limit' => $limit['limit'], 'window' => $limit['window']], $endpoint->limits);
        }

        return $operation;
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParameter(\ReflectionParameter $parameter, Query $query, SchemaGenerator $schemas): array
    {
        $type = $schemas->resolve($parameter);
        $schema = $schemas->forType($this->unwrapNullable($type));
        if ($parameter->isDefaultValueAvailable()) {
            $default = $parameter->getDefaultValue();
            if ($default instanceof \BackedEnum) {
                $schema['default'] = $default->value;
            } elseif (\is_scalar($default)) {
                $schema['default'] = $default;
            }
        }

        return [
            'name' => $query->name ?? $parameter->getName(),
            'in' => 'query',
            'required' => !$parameter->isDefaultValueAvailable() && !$type->isNullable(),
            'schema' => $schema,
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function success(EndpointMetadata $endpoint, \ReflectionMethod $invoke, SchemaGenerator $schemas, bool $limited): array
    {
        $headers = $limited ? ['RateLimit-Policy' => ['$ref' => '#/components/headers/RateLimit-Policy'], 'RateLimit' => ['$ref' => '#/components/headers/RateLimit']] : [];
        $type = $schemas->resolve($invoke);
        if (!$type instanceof NullableType && $type->isIdentifiedBy(TypeIdentifier::VOID, TypeIdentifier::NEVER, TypeIdentifier::NULL)) {
            return [(string) (200 === $endpoint->status ? 204 : $endpoint->status) => array_filter(['description' => 'No content.', 'headers' => $headers])];
        }

        $response = self::responseClass($type);
        if (null !== $response) {
            return [(string) $endpoint->status => array_filter([
                'description' => 'Success.',
                'headers' => $headers,
                'content' => match (true) {
                    is_a($response, BinaryFileResponse::class, true), is_a($response, StreamedResponse::class, true) => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                    is_a($response, JsonResponse::class, true) => ['application/json' => ['schema' => new \ArrayObject()]],
                    default => [],
                },
            ])];
        }

        $envelope = Envelope::from($endpoint->envelope);
        $etag = $this->etag && 'GET' === $endpoint->method && 200 === $endpoint->status;
        if ($etag) {
            $headers['ETag'] = ['description' => 'Version of the answer, to send back in If-None-Match.', 'schema' => ['type' => 'string']];
        }

        return array_filter([
            (string) $endpoint->status => array_filter([
                'description' => 'Success.',
                'headers' => $headers,
                'content' => [$envelope->contentType() => ['schema' => $this->document($this->unwrapNullable($type), $envelope, NameCase::from($endpoint->case), $schemas)]],
            ]),
            '204' => $type instanceof NullableType && 200 === $endpoint->status ? array_filter(['description' => 'No content: the result is null.', 'headers' => $limited ? array_diff_key($headers, ['ETag' => true]) : []]) : null,
            '304' => $etag ? ['description' => 'Not modified: the answer still has the ETag sent in If-None-Match.'] : null,
        ]);
    }

    /**
     * @return class-string<Response>|null
     */
    private static function responseClass(Type $type): ?string
    {
        $type = $type instanceof NullableType ? $type->getWrappedType() : $type;
        if (!$type instanceof ObjectType || !is_a($type->getClassName(), Response::class, true)) {
            return null;
        }

        return $type->getClassName();
    }

    /**
     * @return array<string, mixed>
     */
    private function document(Type $type, Envelope $envelope, NameCase $case, SchemaGenerator $schemas): array
    {
        return match ($envelope) {
            Envelope::Plain => $schemas->forType($type),
            Envelope::Data => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $schemas->forType($type)]],
            Envelope::JsonApi => $this->jsonApiDocument($type, $case, $schemas),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonApiDocument(Type $type, NameCase $case, SchemaGenerator $schemas): array
    {
        $resource = $this->resourceClass($type);
        if (null !== $resource) {
            return ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $this->resource($resource, $case, $schemas)]];
        }

        if ($type instanceof CollectionType && $type->isList()) {
            $item = $this->resourceClass($type->getCollectionValueType());
            if (null !== $item) {
                return ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => $this->resource($item, $case, $schemas)]]];
            }
        }

        return ['type' => 'object', 'required' => ['meta'], 'properties' => ['meta' => $schemas->forType($type)]];
    }

    /**
     * @param class-string $class
     *
     * @return array<string, mixed>
     */
    private function resource(string $class, NameCase $case, SchemaGenerator $schemas): array
    {
        return [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes'],
            'properties' => [
                'type' => ['type' => 'string', 'const' => $this->resourceTypes->typeOfClass($class, $case)],
                'id' => ['type' => 'string'],
                'attributes' => $schemas->forClass($class, false, ['id'], 'Attributes'),
            ],
        ];
    }

    /**
     * @return class-string|null
     */
    private function resourceClass(Type $type): ?string
    {
        if (!$type instanceof ObjectType) {
            return null;
        }

        $class = $type->getClassName();

        return class_exists($class) && property_exists($class, 'id') ? $class : null;
    }

    private function unwrapNullable(Type $type): Type
    {
        return $type instanceof NullableType ? $type->getWrappedType() : $type;
    }

    private function hasQuery(\ReflectionMethod $invoke): bool
    {
        foreach ($invoke->getParameters() as $parameter) {
            if ([] !== $parameter->getAttributes(Query::class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<AuthMethod>    $auth
     * @param list<string>        $scopes
     * @param array<string, true> $schemes
     *
     * @return list<array<string, list<string>>|object>|null
     */
    private function security(array $auth, array $scopes, array &$schemes): ?array
    {
        $requirements = [];
        foreach ($auth as $method) {
            $scheme = match ($method) {
                AuthMethod::ApiKey => 'ApiKey',
                AuthMethod::Jwt => 'Bearer',
                AuthMethod::User => 'Session',
                AuthMethod::Anonymous => null,
            };
            if (null === $scheme) {
                $requirements[] = new \ArrayObject();
                continue;
            }

            $schemes[$scheme] = true;
            $requirements[] = [$scheme => $method->carriesScopes() ? $scopes : []];
        }

        return [AuthMethod::Anonymous] === $auth ? null : $requirements;
    }

    /**
     * @param array<string, true> $schemes
     *
     * @return array<string, array<string, string>>
     */
    private function securitySchemes(array $schemes): array
    {
        $definitions = [
            'ApiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => $this->apiKeyHeader],
            'Bearer' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
            'Session' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => (string) (\ini_get('session.name') ?: 'PHPSESSID')],
        ];

        return array_intersect_key($definitions, $schemes);
    }

    /**
     * @param array<int, true> $errors
     *
     * @return array<string, mixed>
     */
    private function errorResponses(array $errors): array
    {
        ksort($errors);
        $responses = [];
        foreach (array_keys($errors) as $status) {
            [$name, $description, $schema] = self::ERRORS[$status];
            $responses[$name] = array_filter([
                'description' => $description,
                'headers' => 429 === $status ? ['Retry-After' => ['description' => 'Seconds before the call can be retried.', 'schema' => ['type' => 'integer']]] : [],
                'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]],
            ]);
        }

        return $responses;
    }

    /**
     * @param array<int, true> $errors
     *
     * @return array<string, mixed>
     */
    private function problemSchemas(array $errors): array
    {
        if ([] === $errors) {
            return [];
        }

        $schemas = ['Problem' => [
            'type' => 'object',
            'required' => ['type', 'title', 'status'],
            'properties' => [
                'type' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string'],
            ],
        ]];

        if (isset($errors[422])) {
            $schemas['ValidationProblem'] = ['allOf' => [['$ref' => '#/components/schemas/Problem'], [
                'type' => 'object',
                'properties' => ['violations' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['field', 'message'],
                    'properties' => ['field' => ['type' => 'string'], 'message' => ['type' => 'string']],
                ]]],
            ]]];
        }

        if (isset($errors[429])) {
            $schemas['RateLimitProblem'] = ['allOf' => [['$ref' => '#/components/schemas/Problem'], [
                'type' => 'object',
                'properties' => ['limiters' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['name', 'limit', 'remaining', 'reset'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'limit' => ['type' => 'integer'],
                        'remaining' => ['type' => 'integer'],
                        'reset' => ['type' => 'integer', 'description' => 'Seconds before the window resets.'],
                    ],
                ]]],
            ]]];
        }

        return $schemas;
    }

    /**
     * @return array<string, mixed>
     */
    private function rateLimitHeaders(): array
    {
        return [
            'RateLimit-Policy' => ['description' => 'Every limit of the call: name, quota (q) and window in seconds (w).', 'schema' => ['type' => 'string'], 'example' => '"minute";q=600;w=60'],
            'RateLimit' => ['description' => 'State of every limit: remaining calls (r) and seconds before the reset (t).', 'schema' => ['type' => 'string'], 'example' => '"minute";r=581;t=34'],
        ];
    }

    /**
     * @param list<EndpointMetadata> $endpoints
     */
    private function hasLimits(array $endpoints): bool
    {
        return $this->limitsEnforced && ($this->hasPlans || [] !== array_filter($endpoints, static fn (EndpointMetadata $endpoint): bool => [] !== $endpoint->limits));
    }

    /**
     * @param class-string $class
     */
    private function summary(string $class): string
    {
        $short = substr($class, (int) strrpos('\\'.$class, '\\'));

        return ucfirst(strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $short)));
    }

    private function tag(EndpointMetadata $endpoint): string
    {
        $path = $endpoint->path;
        $prefix = $this->groupPrefixes[$endpoint->group] ?? '';
        if ('' !== $prefix && str_starts_with($path, $prefix)) {
            $path = substr($path, \strlen($prefix));
        }

        $segment = explode('/', trim($path, '/'))[0];

        return '' === $segment || str_starts_with($segment, '{') ? $endpoint->group : $segment;
    }
}
