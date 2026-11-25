<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

enum Rating: int
{
    case One = 1;
    case Two = 2;
    case Three = 3;
    case Four = 4;
    case Five = 5;
}
