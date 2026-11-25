<?php

declare(strict_types=1);

namespace Tollbooth\Tests\Fixtures\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TagsInput
{
    /**
     * @param list<TagInput> $tags
     */
    public function __construct(
        #[Assert\Count(min: 1)]
        #[Assert\Valid]
        public array $tags = [],
    ) {
    }
}
