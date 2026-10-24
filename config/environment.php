<?php
declare(strict_types=1);

// Shared by the three entry points and the integration test runner.
$localPath = __DIR__.'/local.php';
$local = is_file($localPath) ? require $localPath : [];
if (!is_array($local)) {
    throw new RuntimeException('config/local.php must return an environment configuration array.');
}
foreach ($local as $key => $value) {
    if (!is_string($key) || !is_string($value) || preg_match('/^[A-Z_]+$/D', $key) !== 1) {
        throw new RuntimeException('Invalid local environment configuration.');
    }
    if (!array_key_exists($key, $_SERVER) && getenv($key) === false) {
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
}
