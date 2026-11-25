<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;
use Tollbooth\Demo\DemoKernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$kernel = new DemoKernel('dev', true);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
