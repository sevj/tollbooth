<?php

declare(strict_types=1);

namespace Tollbooth\Core\Output;

enum Envelope: string
{
    case Plain = 'plain';
    case Data = 'data';
    case JsonApi = 'json_api';

    public function contentType(): string
    {
        return self::JsonApi === $this ? 'application/vnd.api+json' : 'application/json';
    }
}
