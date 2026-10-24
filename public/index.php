<?php
declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;

$kernel = require dirname(__DIR__).'/bootstrap.php';
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
