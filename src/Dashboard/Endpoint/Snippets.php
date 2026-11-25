<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

use Tollbooth\Core\Metadata\EndpointMetadata;

/**
 * @internal
 */
final readonly class Snippets
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $body,
        public ?string $basicAuth,
    ) {
    }

    public static function for(EndpointMetadata $endpoint, ?Operation $operation, string $baseUrl, string $apiKeyHeader): self
    {
        $path = (string) preg_replace_callback('/\{(\w+)\}/', static fn (array $match): string => 'id' === $match[1] || str_ends_with($match[1], 'Id') ? '42' : $match[1], $endpoint->path);
        $query = [];
        foreach ($operation->parameters ?? [] as $parameter) {
            if ('query' === $parameter->in && \in_array('required', $parameter->rules, true)) {
                $query[$parameter->name] = $parameter->name;
            }
        }

        $headers = [];
        $basic = null;
        $auth = $endpoint->auth[0] ?? 'anonymous';
        if ('api_key' === $auth) {
            $headers[$apiKeyHeader] = 'tb_live_…';
        } elseif ('jwt' === $auth) {
            $headers['Authorization'] = 'Bearer <token>';
        } elseif ('user' === $auth) {
            $basic = 'user:password';
        }
        $body = $operation?->bodyExample;
        if (null !== $body) {
            $headers['Content-Type'] = 'application/json';
        }

        return new self($endpoint->method, $baseUrl.$path.([] === $query ? '' : '?'.http_build_query($query)), $headers, $body, $basic);
    }

    public function curl(): string
    {
        $lines = ['curl -X '.$this->method.' '.self::quote($this->url)];
        if (null !== $this->basicAuth) {
            $lines[] = '  -u '.self::quote($this->basicAuth);
        }
        foreach ($this->headers as $name => $value) {
            $lines[] = '  -H '.self::quote($name.': '.$value);
        }
        if (null !== $this->body) {
            $lines[] = '  -d '.self::quote(self::compact($this->body));
        }

        return implode(" \\\n", $lines);
    }

    public function php(): string
    {
        $options = [];
        $headers = $this->headers;
        unset($headers['Content-Type']);
        if (null !== $this->basicAuth) {
            $options[] = "    'auth_basic' => ".self::phpString($this->basicAuth).',';
        }
        if ([] !== $headers) {
            $options[] = "    'headers' => [";
            foreach ($headers as $name => $value) {
                $options[] = '        '.self::phpString($name).' => '.self::phpString($value).',';
            }
            $options[] = '    ],';
        }
        if (null !== $this->body) {
            $options[] = "    'json' => ".self::indent(self::phpValue(json_decode($this->body, true)), 4).',';
        }

        $call = '$response = $client->request('.self::phpString($this->method).', '.self::phpString($this->url);

        return [] === $options ? $call.');' : $call.", [\n".implode("\n", $options)."\n]);";
    }

    public function javascript(): string
    {
        $headers = $this->headers;
        if (null !== $this->basicAuth) {
            $headers['Authorization'] = 'Basic '.base64_encode($this->basicAuth);
        }
        $lines = ['const response = await fetch('.self::jsString($this->url).', {', '  method: '.self::jsString($this->method).','];
        if ([] !== $headers) {
            $lines[] = '  headers: {';
            foreach ($headers as $name => $value) {
                $lines[] = '    '.self::jsString($name).': '.self::jsString($value).',';
            }
            $lines[] = '  },';
        }
        if (null !== $this->body) {
            $lines[] = '  body: JSON.stringify('.self::indent($this->body, 2).'),';
        }
        $lines[] = '});';

        return implode("\n", $lines);
    }

    private static function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private static function compact(string $json): string
    {
        return (string) json_encode(json_decode($json), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function phpString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    private static function jsString(string $value): string
    {
        return (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    private static function phpValue(mixed $value, int $level = 0): string
    {
        if (\is_array($value)) {
            if ([] === $value) {
                return '[]';
            }
            $pad = str_repeat('    ', $level + 1);
            $items = [];
            foreach ($value as $key => $item) {
                $items[] = $pad.(array_is_list($value) ? '' : self::phpString((string) $key).' => ').self::phpValue($item, $level + 1).',';
            }

            return "[\n".implode("\n", $items)."\n".str_repeat('    ', $level).']';
        }

        return match (true) {
            null === $value => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_string($value) => self::phpString($value),
            \is_float($value) => var_export($value, true),
            \is_int($value) => (string) $value,
            default => 'null',
        };
    }

    private static function indent(string $text, int $spaces): string
    {
        return str_replace("\n", "\n".str_repeat(' ', $spaces), $text);
    }
}
