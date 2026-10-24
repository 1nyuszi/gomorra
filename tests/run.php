<?php
declare(strict_types=1);

use App\Adapter\Cli\CreateSchemaCommand;
use App\Adapter\Mcp\SchemaMcpTool;
use App\Adapter\Mcp\McpServer;
use App\Core\Exception\InvalidSchema;
use App\Core\Database\DynamicEntityManager;
use App\Core\Exception\SchemaAlreadyExists;
use App\Core\Service\JsonSchemaParser;
use App\Core\Service\SchemaManager;
use App\Infrastructure\Persistence\FileSchemaRepository;
use App\Infrastructure\Persistence\MySqlConnectionFactory;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/config/environment.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function invalid(callable $operation): void
{
    try {
        $operation();
    } catch (InvalidSchema) {
        return;
    }
    throw new RuntimeException('Expected invalid schema or data.');
}

$storage = tempnam(sys_get_temp_dir(), 'gomorra-');
check($storage !== false, 'Temporary storage');
$fixture = tempnam(sys_get_temp_dir(), 'gomorra-schema-');
check($fixture !== false, 'Temporary schema fixture');
$testDsn = $_SERVER['TEST_DATABASE_DSN'] ?? getenv('TEST_DATABASE_DSN');
check(is_string($testDsn) && $testDsn !== '', 'Set TEST_DATABASE_DSN to a dedicated MySQL test database.');
$user = $_SERVER['DATABASE_USER'] ?? getenv('DATABASE_USER');
$password = $_SERVER['DATABASE_PASSWORD'] ?? getenv('DATABASE_PASSWORD');
$pdo = MySqlConnectionFactory::create($testDsn, $user, $password);
$devPdo = MySqlConnectionFactory::create($_SERVER['DATABASE_DSN'] ?? getenv('DATABASE_DSN'), $user, $password);
check($pdo->query('SELECT DATABASE()')->fetchColumn() !== $devPdo->query('SELECT DATABASE()')->fetchColumn(), 'Tests must not use the development database.');
unset($devPdo);
$_SERVER['DATABASE_DSN'] = $testDsn;
putenv('DATABASE_DSN='.$testDsn);
$_SERVER['SCHEMA_STORAGE'] = $storage;
putenv('SCHEMA_STORAGE='.$storage);
$prefix = 'Test'.bin2hex(random_bytes(6));
$tables = [];
$kernel = null;
try {
    $input = json_decode(file_get_contents(dirname(__DIR__).'/examples/customer.json'), true, 512, JSON_THROW_ON_ERROR);
    $input['name'] = $prefix.'Customer';
    $parser = new JsonSchemaParser();
    $definition = $parser->parse($input);
    check($parser->hydrate($definition, ['email' => 'a@example.com', 'age' => 21])->values['age'] === 21, 'Runtime entity');
    invalid(fn () => $parser->hydrate($definition, ['age' => 21]));
    invalid(fn () => $parser->hydrate($definition, ['email' => 'a', 'age' => '21']));
    invalid(fn () => $parser->hydrate($definition, ['email' => 'a', 'unknown' => true]));
    invalid(fn () => $parser->parse(array_replace_recursive($input, ['schema' => ['properties' => ['email' => ['format' => 'email']]]])));
    invalid(fn () => $parser->parse(array_replace_recursive($input, ['schema' => ['required' => ['missing']]])));
    invalid(fn () => $parser->parse(array_replace_recursive($input, ['schema' => ['additionalProperties' => null]])));

    $database = new DynamicEntityManager($pdo, $parser);
    $repository = new FileSchemaRepository($storage);
    $manager = new SchemaManager($parser, $repository, $database);
    foreach (['Customer', 'ApiCustomer', 'McpCustomer', 'StdioCustomer', 'UnsafeCustomer', 'RetryCustomer'] as $suffix) {
        $tables[] = $database->tableName($prefix.$suffix);
    }
    file_put_contents($fixture, json_encode($input, JSON_THROW_ON_ERROR));
    $cli = new CommandTester(new CreateSchemaCommand($manager));
    check($cli->execute(['file' => $fixture]) === 0, 'CLI create');
    check($cli->execute(['file' => $fixture]) === 2, 'CLI duplicate');

    $table = $database->tableName($input['name']);
    $columns = $pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    check($columns['_id']['Key'] === 'PRI' && $columns['_id']['Extra'] === 'auto_increment', 'Managed primary key');
    check($columns['email']['Type'] === 'longtext' && $columns['email']['Null'] === 'NO', 'Required string SQL mapping');
    check($columns['age']['Type'] === 'bigint' && $columns['age']['Null'] === 'YES', 'Optional integer SQL mapping');
    check($columns['active']['Type'] === 'tinyint(1)', 'Boolean SQL mapping');
    $pdo->prepare('INSERT INTO `'.$table.'` (`email`, `age`, `active`) VALUES (?, ?, ?)')->execute(['persist@example.com', 32, 1]);
    $updated = $input;
    $updated['schema']['properties']['balance'] = ['type' => 'number'];
    $manager->update($updated);
    $database->synchronize($parser->parse($updated));
    check($pdo->query('SELECT `balance` FROM `'.$table.'`')->fetchColumn() === null, 'Added optional column defaults to NULL');
    check($pdo->query('SELECT `email` FROM `'.$table.'`')->fetchColumn() === 'persist@example.com', 'ALTER preserves rows');
    check(isset($repository->find($input['name'])->schema['properties']['balance']), 'Update persists metadata');
    $badUpdate = $updated;
    $badUpdate['schema']['properties']['age']['type'] = 'string';
    invalid(fn () => $manager->update($badUpdate));
    $badUpdate = $updated;
    unset($badUpdate['schema']['properties']['age']);
    invalid(fn () => $manager->update($badUpdate));
    $badUpdate = $updated;
    $badUpdate['schema']['required'][] = 'age';
    invalid(fn () => $manager->update($badUpdate));
    $badUpdate = $updated;
    $badUpdate['schema']['properties']['optional_before_error'] = ['type' => 'string'];
    $badUpdate['schema']['properties']['required_after_error'] = ['type' => 'string'];
    $badUpdate['schema']['required'][] = 'required_after_error';
    invalid(fn () => $manager->update($badUpdate));
    $columnNames = $pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
    check(!in_array('optional_before_error', $columnNames, true), 'All changes preflight before ALTER');
    check(!isset($repository->find($input['name'])->schema['properties']['optional_before_error']), 'Rejected update leaves metadata unchanged');
    try {
        $pdo->exec('INSERT INTO `'.$table.'` (`age`) VALUES (1)');
        throw new RuntimeException('NOT NULL must be enforced by MySQL.');
    } catch (PDOException $error) {
        check($error->getCode() === 'HY000' || $error->getCode() === '23000', 'Required column SQL constraint');
    }
    $pdo->beginTransaction();
    try {
        try {
            $database->synchronize($parser->parse($updated));
            throw new RuntimeException('DDL should reject active transactions.');
        } catch (LogicException) {
            check($pdo->inTransaction(), 'DDL did not implicitly commit caller transaction');
        }
    } finally {
        $pdo->rollBack();
    }
    try {
        $caseDuplicate = $input;
        $caseDuplicate['name'] = strtoupper($input['name']);
        $manager->create($caseDuplicate);
        throw new RuntimeException('Case-insensitive duplicate should fail.');
    } catch (SchemaAlreadyExists) {
    }
    $unsafe = $input;
    $unsafe['name'] = $prefix.'UnsafeCustomer';
    foreach ([['_id' => ['type' => 'integer']], ['Name' => ['type' => 'string'], 'name' => ['type' => 'string']], [str_repeat('a', 65) => ['type' => 'string']], ['x`; DROP TABLE anything' => ['type' => 'string']]] as $properties) {
        $unsafe['schema']['properties'] = $properties;
        $unsafe['schema']['required'] = [];
        invalid(fn () => $manager->create($unsafe));
        check($repository->find($unsafe['name']) === null, 'Invalid SQL definition must not persist metadata');
    }
    $retry = $input;
    $retry['name'] = $prefix.'RetryCustomer';
    // Simulate DDL succeeding before a metadata failure, then retry schema creation.
    $database->synchronize($parser->parse($retry));
    $manager->create($retry);
    check($repository->find($retry['name']) !== null, 'DDL-before-metadata retry');

    $lockName = 'gomorra:'.substr(hash('sha256', $pdo->query('SELECT DATABASE()')->fetchColumn().'.'.$table), 0, 56);
    try {
        $database->withSchemaLock($input['name'], static fn () => throw new RuntimeException('lock-test'));
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'lock-test', 'Lock callback failure');
    }
    $otherConnection = MySqlConnectionFactory::create($testDsn, $user, $password);
    $statement = $otherConnection->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([$lockName]);
    check((int) $statement->fetchColumn() === 1, 'Advisory locks released after nested calls and failures');
    $otherConnection->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);

    $kernel = require dirname(__DIR__).'/bootstrap.php';
    $request = Request::create('/api/schemas', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($input, JSON_THROW_ON_ERROR));
    check($kernel->handle($request)->getStatusCode() === 409, 'HTTP sees CLI persistence');
    $input['name'] = $prefix.'ApiCustomer';
    $request = Request::create('/api/schemas', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($input, JSON_THROW_ON_ERROR));
    check($kernel->handle($request)->getStatusCode() === 201, 'HTTP create');
    $pdo->query('SELECT * FROM `'.$database->tableName($input['name']).'` LIMIT 0');
    $request = Request::create('/api/schemas', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{');
    check($kernel->handle($request)->getStatusCode() === 400, 'Malformed JSON');
    $request = Request::create('/api/schemas', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"bad"}');
    check($kernel->handle($request)->getStatusCode() === 422, 'Invalid schema');
    check($kernel->handle(Request::create('/api/schemas', 'GET'))->getStatusCode() === 405, 'HTTP method restriction');

    $tool = new SchemaMcpTool($manager);
    check($tool->execute($input)['isError'] === true, 'MCP sees API persistence');
    $input['name'] = $prefix.'McpCustomer';
    check($tool->execute($input)['structuredContent']['name'] === $input['name'], 'MCP create');
    $pdo->query('SELECT * FROM `'.$database->tableName($input['name']).'` LIMIT 0');
    check($tool->execute(['name' => 'bad'])['isError'] === true, 'MCP domain error');

    $server = new McpServer($tool);
    $message = static fn (array $value): object => json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    check($server->dispatch($message(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']))['error']['code'] === -32000, 'MCP lifecycle');
    $init = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]];
    check($server->dispatch($message($init))['result']['protocolVersion'] === '2025-06-18', 'MCP initialize');
    check($server->dispatch($message(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])) === null, 'MCP notifications');
    check(count($server->dispatch($message(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']))['result']['tools']) === 2, 'MCP discovery');

    require __DIR__.'/mcp.php';

    // Exercise the actual stdio entry point and ensure stdout contains only JSON-RPC.
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/mcp'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__));
    check(is_resource($process), 'MCP subprocess');
    $input['name'] = $prefix.'StdioCustomer';
    $stdioUpdate = $input;
    $stdioUpdate['schema']['properties']['stdio_field'] = ['type' => 'integer'];
    foreach ([$init, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'create_dynamic_schema', 'arguments' => $input]], ['jsonrpc' => '2.0', 'id' => 'update', 'method' => 'tools/call', 'params' => ['name' => 'update_dynamic_schema', 'arguments' => $stdioUpdate]]] as $frame) {
        fwrite($pipes[0], json_encode($frame, JSON_THROW_ON_ERROR)."\n");
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'MCP process failed: '.$stderr);
    $frames = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim($stdout)));
    check(count($frames) === 3 && $frames[1]['result']['structuredContent']['name'] === $input['name'], 'MCP stdio create');
    check($frames[2]['id'] === 'update' && isset($frames[2]['result']['structuredContent']['schema']['properties']['stdio_field']), 'MCP stdio update');
    $pdo->query('SELECT * FROM `'.$database->tableName($input['name']).'` LIMIT 0');
    fwrite(STDOUT, "PASS: MySQL, validation, persistence, CLI, REST, MCP JSON-RPC, HTTP and STDIO create/update.\n");
} finally {
    $kernel?->shutdown();
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
    }
    unlink($storage);
    unlink($fixture);
    putenv('SCHEMA_STORAGE');
    unset($_SERVER['SCHEMA_STORAGE']);
}
