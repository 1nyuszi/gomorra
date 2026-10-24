# Gomorra low-code engine

A lean PHP 8.5 / Symfony 8.0 application implementing **Create Dynamic Schema** through HTTP, CLI and MCP. Each adapter invokes the same `SchemaManager`. The Core imports no Symfony, HTTP, Console or MCP classes.

## Structure

```text
bin/
  console                         Symfony Console entry point
  mcp                             MCP stdio entry point
bootstrap.php                     Boots the shared kernel/container
composer.json
composer.lock
config/
  bundles.php
  environment.php                 Loads local settings without overriding environment
  local.php.dist                  Copy to ignored local.php for credentials
  packages/framework.yaml
  routes.yaml                     Loads controller route attributes
  services.yaml                   Autowiring and repository binding
examples/customer.json
public/index.php                  HTTP front controller
src/
  Kernel.php                      Framework composition root
  Core/
    Database/DynamicEntityManager.php  Raw PDO MySQL table synchronization
    Exception/
      InvalidSchema.php
      SchemaAlreadyExists.php
    Model/
      SchemaDefinition.php        Parsed metadata
      DynamicEntity.php           Runtime data and its definition
    Port/
      SchemaRepository.php        Persistence contract
    Service/
      JsonSchemaParser.php        Schema parsing and runtime hydration
      SchemaManager.php           Shared create use case
  Adapter/
    Api/SchemaController.php      #[AsController], #[Route]
    Cli/CreateSchemaCommand.php   #[AsCommand]
    Mcp/
      McpServer.php               Shared JSON-RPC dispatcher, STDIO/HTTP listeners
      SchemaMcpTool.php           Explicit create/update tool definitions and execution
      StdioServer.php             Compatibility wrapper around McpServer
  Infrastructure/
    Persistence/FileSchemaRepository.php
    Persistence/MySqlConnectionFactory.php
tests/run.php                     MySQL and adapter integration tests
tests/mcp.php                     JSON-RPC and HTTP MCP cases included by run.php
var/                              Generated cache and local schema registry
```

Dependencies point inward: adapters depend on Core services; the file repository implements a Core port. Only the composition root chooses the implementation. Future workflow and dynamic entity use cases belong in `Core/Service` and can be exposed through new adapter methods.

## Install and verify

Requires PHP 8.5+, MySQL 8.0+, Composer, PDO MySQL (`pdo_mysql`), and extensions required by Symfony (including JSON, XML, ctype and iconv or their supported polyfills). Create development and test databases with character set `utf8mb4`. Copy `config/local.php.dist` to `config/local.php` and fill in the credentials; this file is ignored by Git. The supplied local setup uses `gomorra_dev` and `gomorra_test` on `localhost:3306`.

```sh
composer install
php bin/console lint:container
php bin/console debug:router
composer test
```

The requested Symfony 8.0 line is out of maintenance. During generation, Composer blocked resolution because of security advisories against the available 8.0 versions. Local verification used `composer install --no-interaction --prefer-dist --no-security-blocking` as a one-time override. The project does **not** disable Composer's advisory policy. Review `composer audit` and move to a supported, patched release before deployment. Direct Symfony requirements are pinned to `8.0.*`; compatible transitive components can resolve to newer minor versions. The lock file records the tested dependency set.

Tests require `TEST_DATABASE_DSN` and use the same credentials as the development connection. They refuse to run when the test and development database names match. They use a temporary metadata registry and unique table names, then remove only their own test tables. Coverage includes MySQL CREATE/ALTER, data preservation, rejected destructive changes, retry after DDL succeeds without metadata, advisory-lock release, metadata rejection, runtime values, duplicate creation, all delivery adapters and actual subprocess stdio execution.

## Define Customer

All entry points accept the same metadata envelope:

```json
{
  "name": "Customer",
  "schema": {
    "type": "object",
    "properties": {
      "email": {"type": "string"},
      "age": {"type": "integer"},
      "active": {"type": "boolean"}
    },
    "required": ["email"],
    "additionalProperties": false
  }
}
```

This is an explicit JSON Schema subset, not a full JSON Schema validator. It supports a root object, nonempty properties with scalar types `string`, `integer`, `number`, `boolean`, unique `required` field names, and `additionalProperties: false` (also the default). It rejects unsupported keywords, nested objects, arrays, nullable fields, defaults, formats, references and coercion. The schema name must match `^[A-Z][A-Za-z0-9_]{0,63}$`; SQL-backed creation rejects schema names and field names that differ only in case. SQL field names have a 64-character limit; `_id` is reserved for the generated primary key. Existing schemas can receive optional fields through the Core update method.

The parser builds a runtime definition rather than generating PHP classes or using `eval`. Runtime data can be hydrated and validated independently of a transport:

```php
<?php
declare(strict_types=1);

use App\Core\Service\JsonSchemaParser;

require 'vendor/autoload.php';
$parser = new JsonSchemaParser();
$metadata = json_decode(file_get_contents('examples/customer.json'), true, 512, JSON_THROW_ON_ERROR);
$definition = $parser->parse($metadata);
$customer = $parser->hydrate($definition, ['email' => 'alice@example.com', 'age' => 32]);
echo $customer->values['email'];
```

## CLI

```sh
php bin/console schema:create examples/customer.json
```

Success prints the created schema as JSON and exits `0`. Invalid metadata and duplicate names exit `2`. Unexpected infrastructure failures are handled by Symfony Console.

`bin/console` boots the shared kernel/container directly. `CreateSchemaCommand` is discovered through `#[AsCommand]` and the autoconfigured `App\Adapter\Cli\` service resource. It invokes `SchemaManager` in-process and requires only a running MySQL server and writable metadata storage; no HTTP server or HTTP request is involved.

Run `composer test:cli` to verify the real console entry point independently. This test launches `bin/console` from outside the project directory, checks command discovery, schema metadata, MySQL columns and exit codes, then removes its own test table. It uses `gomorra_test` through `TEST_DATABASE_DSN` and starts no HTTP listener.

## REST

Start a local development server (in a separate terminal):

```sh
php -S 127.0.0.1:8000 -t public public/index.php
```

```sh
curl -X POST http://127.0.0.1:8000/api/schemas -H "Content-Type: application/json" --data-binary @examples/customer.json
```

On Windows PowerShell use `curl.exe` to avoid the older `curl` alias. The response is the created metadata with HTTP `201`. Malformed JSON returns `400`, invalid metadata `422`, duplicate names `409`, and unsupported HTTP methods `405`. Running this after the CLI example returns `409`, because all entry points share storage; change the schema name to create another entity. Creation now also creates the underlying MySQL table; it does not insert Customer records.

## Runtime MySQL tables

`SchemaManager::create()` invokes `Core/Database/DynamicEntityManager::synchronize()` before persisting metadata. Every delivery adapter therefore creates the same SQL structure. `Customer` maps to `lc_customer`; long names are truncated with a deterministic hash suffix to fit MySQL's 64-character limit. Identifiers are validated and backtick-quoted; metadata queries use prepared statements. Tables use InnoDB and `utf8mb4_unicode_ci`.

| Metadata type | MySQL column type |
| --- | --- |
| string | LONGTEXT |
| integer | BIGINT |
| number | DOUBLE |
| boolean | TINYINT(1) |

Every table receives `_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`. Required properties are `NOT NULL`; optional properties allow SQL `NULL`. `JsonSchemaParser::hydrate()` remains the application-level validator: MySQL type coercion and its `TINYINT(1)` representation do not enforce every JSON scalar rule.

To evolve a registered schema from application code, use the same manager:

```php
<?php
declare(strict_types=1);

use App\Core\Database\DynamicEntityManager;
use App\Core\Service\JsonSchemaParser;
use App\Core\Service\SchemaManager;
use App\Infrastructure\Persistence\FileSchemaRepository;
use App\Infrastructure\Persistence\MySqlConnectionFactory;

require 'vendor/autoload.php';
require 'config/environment.php';
$pdo = MySqlConnectionFactory::create(getenv('DATABASE_DSN'), getenv('DATABASE_USER'), getenv('DATABASE_PASSWORD'));
$parser = new JsonSchemaParser();
$database = new DynamicEntityManager($pdo, $parser);
$manager = new SchemaManager($parser, new FileSchemaRepository(__DIR__.'/var/schemas.json'), $database);
$metadata = json_decode(file_get_contents('examples/customer.json'), true, 512, JSON_THROW_ON_ERROR);
$metadata['schema']['properties']['loyaltyPoints'] = ['type' => 'integer'];
$manager->update($metadata); // Customer must already exist; adds a nullable column.
```

The existing create endpoints retain duplicate errors. Updates are exposed through the Core service and the MCP `update_dynamic_schema` tool. `DynamicEntityManager::synchronize()` can also be called directly to provision or repair a table without writing metadata. Repeating synchronization with the same definition performs no DDL.

Updates add optional columns only. Column removal/rename, type changes, nullability changes and new required columns are rejected before issuing ALTER; these require a deliberate migration and backfill. One ALTER contains all approved additions, preserving existing records. An existing table with incompatible columns, engine, defaults, or `_id` is rejected.

MySQL advisory locks serialize schema operations across cooperating engine connections, including metadata writes. Do not call these operations inside an existing PDO transaction: [MySQL DDL implicitly commits](https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html). DDL and the JSON registry are **not atomic together**. Metadata is written only after successful DDL. If metadata writing fails, the SQL change remains; retry the same operation once storage is healthy. Introspection makes that retry safe when the registry is still readable. Keep one registry per database, shared by all engine instances. Manual SQL changes and other applications do not honor these advisory locks.

The connection needs `SELECT`, `CREATE`, and `ALTER` privileges for runtime schema operations. Tests additionally need `INSERT` and `DROP` on the dedicated test database. Environment settings override `config/local.php`: `DATABASE_DSN`, `DATABASE_USER`, `DATABASE_PASSWORD`, and `TEST_DATABASE_DSN`. No SQLite driver or fallback is used.

## MCP

`Adapter/Mcp/McpServer.php` implements a shared JSON-RPC 2.0 dispatcher for MCP `2025-06-18`. It supports initialization, ping, `tools/list` and `tools/call`. Only two explicitly registered tools are exposed; other PHP methods cannot be invoked through reflection:

| Tool | Core method | Behavior |
| --- | --- | --- |
| `create_dynamic_schema` | `SchemaManager::create()` | Creates metadata and a MySQL table; duplicate names fail |
| `update_dynamic_schema` | `SchemaManager::update()` | Accepts the complete definition and adds optional columns |

Each tool advertises its JSON input schema. Both return text content and structured metadata. Domain validation/duplicate errors return `isError: true`. Unknown methods/tools, invalid envelopes and infrastructure failures produce JSON-RPC errors; internal exception details remain in stderr/server logs. Batches, resources, prompts, server-initiated requests and workflows are not implemented.

### STDIO

Configure an MCP client to launch `php` with an **absolute path** to `bin/mcp` as its argument, or run `php bin/mcp` directly. The listener reads newline-delimited messages and writes only JSON-RPC frames to stdout. Complete `initialize` and `notifications/initialized` before calling tools. The old `StdioServer` entry class delegates to the same dispatcher.

Example conversation on stdin, one JSON object per line:

```jsonl
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"example","version":"1.0"}}}
{"jsonrpc":"2.0","method":"notifications/initialized"}
{"jsonrpc":"2.0","id":2,"method":"tools/list"}
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"create_dynamic_schema","arguments":{"name":"McpCustomer","schema":{"type":"object","properties":{"email":{"type":"string"}},"required":["email"],"additionalProperties":false}}}}
{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"update_dynamic_schema","arguments":{"name":"McpCustomer","schema":{"type":"object","properties":{"email":{"type":"string"},"loyaltyPoints":{"type":"integer"}},"required":["email"],"additionalProperties":false}}}}
```

### HTTP

Start the Symfony front controller with `php -S 127.0.0.1:8000 -t public public/index.php`. The endpoint is `http://127.0.0.1:8000/mcp`. It uses stateless Streamable HTTP with JSON responses, no sessions and no SSE stream. POST accepts one JSON-RPC message; GET and DELETE return `405`. Notifications and accepted client responses return an empty `202` response. See the [MCP transport specification](https://modelcontextprotocol.io/specification/2025-06-18/basic/transports).

```sh
curl -X POST http://127.0.0.1:8000/mcp -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" --data-binary @examples/mcp-initialize.json
curl -X POST http://127.0.0.1:8000/mcp -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -H "MCP-Protocol-Version: 2025-06-18" --data-binary @examples/mcp-tools-list.json
```

Use `curl.exe` on Windows PowerShell. Send `notifications/initialized`, followed by the same tool-call envelopes shown above, as separate POST bodies. Include both advertised Accept types and `MCP-Protocol-Version: 2025-06-18` after initialization. HTTP lifecycle state is not persisted across clients; the protocol header identifies the version for each call.

When an Origin header is present, it must match `MCP_ALLOWED_ORIGINS` (a comma-separated environment setting, also accepted in `config/local.php`). Defaults allow `http://localhost` and `http://127.0.0.1`, with or without port `8000`. Unlisted origins receive `403`. This base listener has no authentication layer; use it locally and add authentication before remote exposure.

## Container and storage

`bootstrap.php` boots the same kernel for all three entry points. `config/services.yaml` autowires Core services and adapters, registers attribute-based controllers/commands, and binds `SchemaRepository` to `FileSchemaRepository`. Core constructors use plain PHP type hints and need no framework attributes. Only MCP entry services are public for direct lookup by the stdio bootstrap.

The registry defaults to `var/schemas.json`; set `SCHEMA_STORAGE` to an absolute path to use another file. `APP_ENV` defaults to `dev`, `APP_DEBUG` to `0`. After configuration/code changes with debug off, run `php bin/console cache:clear`; use `APP_DEBUG=1` during active development.

The repository holds an exclusive file lock over duplicate checking and writing, so cooperating local processes cannot insert the same name concurrently. It is a reference local store: it has no crash recovery or distributed transaction guarantees. For production, implement the Core repository port using a database transaction and a unique constraint. Configure the web server document root as `public/`, replace the development framework secret, and add authentication/authorization before exposing administrative operations.

Reference documentation: [Symfony attribute routing](https://symfony.com/doc/8.0/routing.html), [Symfony autowiring](https://symfony.com/doc/8.0/service_container/autowiring.html), [MCP 2025-06-18 specification](https://modelcontextprotocol.io/specification/2025-06-18).
