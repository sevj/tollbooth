<?php

declare(strict_types=1);

namespace Tollbooth\Core\Attribute;

use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Body extends MapRequestPayload
{
}
