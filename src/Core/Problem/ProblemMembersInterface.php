<?php

declare(strict_types=1);

namespace Tollbooth\Core\Problem;

interface ProblemMembersInterface
{
    /**
     * @return array<string, mixed>
     */
    public function problemMembers(): array;
}
