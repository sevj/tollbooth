<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

enum Category: string
{
    case Electronics = 'electronics';
    case Home = 'home';
    case Garden = 'garden';
    case Toys = 'toys';
}
