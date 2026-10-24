<?php
declare(strict_types=1);

use App\Kernel;

require_once __DIR__.'/vendor/autoload.php';
require __DIR__.'/config/environment.php';

// Every delivery entry point boots exactly the same service container.
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', ($_SERVER['APP_DEBUG'] ?? '0') === '1');
$kernel->boot();

return $kernel;
