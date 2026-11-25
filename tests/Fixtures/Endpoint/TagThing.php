<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Endpoint;

use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Tests\Fixtures\Model\TagInput;
use Tollbooth\Tests\Fixtures\Model\TagsInput;

#[Endpoint('POST', '/things/{id}/tags')]
final class TagThing
{
    /**
     * @return list<string>
     */
    public function __invoke(int $id, #[Body] TagsInput $input): array
    {
        return array_map(static fn (TagInput $tag): string => $tag->label.':'.$tag->color->value, $input->tags);
    }
}
