<?php
declare(strict_types=1);

use App\Core\Database\DynamicEntityManager;
use App\Core\Service\JsonSchemaParser;
use App\Infrastructure\Persistence\FileSchemaRepository;
use App\Infrastructure\Persistence\MySqlConnectionFactory;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require $root.'/config/environment.php';

function verifyCli(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$testDsn = $_SERVER['TEST_DATABASE_DSN'] ?? getenv('TEST_DATABASE_DSN');
verifyCli(is_string($testDsn) && $testDsn !== '', 'TEST_DATABASE_DSN must identify a dedicated MySQL test database.');
$user = $_SERVER['DATABASE_USER'] ?? getenv('DATABASE_USER');
$password = $_SERVER['DATABASE_PASSWORD'] ?? getenv('DATABASE_PASSWORD');
$pdo = MySqlConnectionFactory::create($testDsn, $user, $password);
$dev = MySqlConnectionFactory::create($_SERVER['DATABASE_DSN'] ?? getenv('DATABASE_DSN'), $user, $password);
verifyCli($pdo->query('SELECT DATABASE()')->fetchColumn() !== $dev->query('SELECT DATABASE()')->fetchColumn(), 'Test and development databases must be different.');
unset($dev);

$storage = tempnam(sys_get_temp_dir(), 'gomorra-cli-registry-');
$fixture = tempnam(sys_get_temp_dir(), 'gomorra-cli-schema-');
verifyCli($storage !== false && $fixture !== false, 'Cannot create temporary CLI test files.');
$metadata = json_decode(file_get_contents($root.'/examples/customer.json'), true, 512, JSON_THROW_ON_ERROR);
$metadata['name'] = 'CliTest'.bin2hex(random_bytes(8));
$database = new DynamicEntityManager($pdo, new JsonSchemaParser());
$table = $database->tableName($metadata['name']);

$environment = getenv();
$environment['DATABASE_DSN'] = $testDsn;
$environment['DATABASE_USER'] = $user;
$environment['DATABASE_PASSWORD'] = $password;
$environment['SCHEMA_STORAGE'] = $storage;
$environment['APP_ENV'] = 'test';
$environment['APP_DEBUG'] = '0';

// Invoke only the CLI entry point, from a different cwd. No HTTP server is started.
$run = static function (array $arguments) use ($root, $environment): array {
    $process = proc_open(
        [PHP_BINARY, $root.'/bin/console', '--no-interaction', '--no-ansi', ...$arguments],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        sys_get_temp_dir(),
        $environment,
    );
    verifyCli(is_resource($process), 'Cannot launch bin/console.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

try {
    file_put_contents($fixture, json_encode($metadata, JSON_THROW_ON_ERROR));
    $result = $run(['list', '--raw']);
    verifyCli($result['exit'] === 0 && preg_match('/^schema:create\s/m', $result['stdout']) === 1, 'CreateSchemaCommand was not registered: '.$result['stderr']);

    $result = $run(['schema:create', $fixture]);
    verifyCli($result['exit'] === 0, 'CLI creation failed: '.$result['stderr'].$result['stdout']);
    $created = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);
    verifyCli($created['name'] === $metadata['name'], 'CLI did not return the created schema.');
    verifyCli((new FileSchemaRepository($storage))->find($metadata['name']) !== null, 'CLI did not persist metadata.');
    $columns = $pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
    verifyCli($columns === ['_id', 'email', 'age', 'active'], 'CLI did not create the expected MySQL table.');

    $result = $run(['schema:create', $fixture]);
    verifyCli($result['exit'] === 2 && str_contains($result['stdout'], 'already exists'), 'Duplicate CLI creation must exit 2.');
    file_put_contents($fixture, '{');
    verifyCli($run(['schema:create', $fixture])['exit'] === 2, 'Malformed CLI input must exit 2.');

    fwrite(STDOUT, "PASS: bin/console discovers schema:create, creates metadata and MySQL table, and handles invalid input without starting an HTTP server.\n");
} finally {
    $pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
    unlink($storage);
    unlink($fixture);
}
