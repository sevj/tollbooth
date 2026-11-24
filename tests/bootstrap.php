<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

(new Filesystem())->remove(sys_get_temp_dir().'/tollbooth-tests');
