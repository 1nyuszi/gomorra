<?php
declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Exception\InvalidSchema;
use App\Core\Model\SchemaDefinition;
use App\Core\Service\JsonSchemaParser;
use PDO;

/** MySQL DDL only. No ORM, HTTP, Console or MCP dependencies. */
final readonly class DynamicEntityManager
{
    public function __construct(private PDO $pdo, private JsonSchemaParser $parser)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \InvalidArgumentException('DynamicEntityManager requires PDO MySQL.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function tableName(string $name): string
    {
        if (preg_match('/^[A-Z][A-Za-z0-9_]{0,63}$/D', $name) !== 1) {
            throw new InvalidSchema('Invalid schema name.');
        }
        $table = 'lc_'.strtolower($name);

        return strlen($table) <= 64 ? $table : substr($table, 0, 47).'_'.substr(hash('sha256', strtolower($name)), 0, 16);
    }

    /** Serialize DDL and its associated metadata write across engine processes. */
    public function withSchemaLock(string $name, callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('MySQL DDL cannot run inside an application transaction.');
        }
        $database = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') {
            throw new \LogicException('The MySQL DSN must select a database.');
        }
        $lock = 'gomorra:'.substr(hash('sha256', $database.'.'.$this->tableName($name)), 0, 56);
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, 10)');
        $statement->execute(['name' => $lock]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Timed out acquiring the schema DDL lock.');
        }
        try {
            return $operation();
        } finally {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $statement->execute(['name' => $lock]);
        }
    }

    /** Create a table or add optional columns; reject destructive schema changes. */
    public function synchronize(SchemaDefinition $definition): void
    {
        // Definitions are public value objects: validate even when called without SchemaManager.
        $definition = $this->parser->parse($definition->toArray());
        $this->withSchemaLock($definition->name, function () use ($definition): void {
            $table = $this->tableName($definition->name);
            $properties = $definition->schema['properties'];
            $seen = ['_id' => true];
            foreach ($properties as $field => $metadata) {
                $this->quoteIdentifier($field);
                if (isset($seen[strtolower($field)])) {
                    throw new InvalidSchema('Field names must be unique ignoring case; _id is reserved.');
                }
                $seen[strtolower($field)] = true;
            }

            $statement = $this->pdo->prepare('SELECT TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
            $statement->execute(['table' => $table]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if ($existing === false) {
                $columns = ['`_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'];
                foreach ($properties as $field => $metadata) {
                    $columns[] = $this->column($field, $metadata['type'], in_array($field, $definition->schema['required'], true));
                }
                $this->pdo->exec('CREATE TABLE '.$this->quoteIdentifier($table).' ('.implode(', ', $columns).') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

                return;
            }
            if ($existing['TABLE_TYPE'] !== 'BASE TABLE' || strcasecmp((string) $existing['ENGINE'], 'InnoDB') !== 0) {
                throw new InvalidSchema('The target must be an InnoDB base table.');
            }

            $statement = $this->pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ORDER BY ORDINAL_POSITION');
            $statement->execute(['table' => $table]);
            $columns = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $columns[$column['COLUMN_NAME']] = $column;
            }
            $id = $columns['_id'] ?? [];
            if (($id['DATA_TYPE'] ?? '') !== 'bigint' || !str_contains($id['COLUMN_TYPE'] ?? '', 'unsigned') || ($id['COLUMN_KEY'] ?? '') !== 'PRI' || ($id['IS_NULLABLE'] ?? '') !== 'NO' || !str_contains($id['EXTRA'] ?? '', 'auto_increment')) {
                throw new InvalidSchema('Existing table does not have the engine-managed _id primary key.');
            }
            unset($columns['_id']);
            // Preflight every existing column before issuing any ALTER statement.
            foreach ($columns as $field => $column) {
                if (!array_key_exists($field, $properties)) {
                    throw new InvalidSchema(sprintf('Dropping or renaming column %s requires an explicit migration.', $field));
                }
                $type = $properties[$field]['type'];
                if ($column['DATA_TYPE'] !== $this->sqlType($type) || str_contains($column['COLUMN_TYPE'], 'unsigned') || ($type === 'boolean' && !preg_match('/^tinyint\(1\)$/', $column['COLUMN_TYPE']))) {
                    throw new InvalidSchema(sprintf('Changing the SQL type of %s requires an explicit migration.', $field));
                }
                $nullable = in_array($field, $definition->schema['required'], true) ? 'NO' : 'YES';
                if ($column['IS_NULLABLE'] !== $nullable || $column['EXTRA'] !== '' || !in_array($column['COLUMN_DEFAULT'], [null, 'NULL'], true)) {
                    throw new InvalidSchema(sprintf('Changing constraints or defaults of %s requires an explicit migration.', $field));
                }
            }
            $additions = [];
            foreach ($properties as $field => $metadata) {
                if (isset($columns[$field])) {
                    continue;
                }
                if (in_array($field, $definition->schema['required'], true)) {
                    throw new InvalidSchema(sprintf('New required column %s needs an explicit backfill migration.', $field));
                }
                $additions[] = 'ADD COLUMN '.$this->column($field, $metadata['type'], false);
            }
            if ($additions !== []) {
                $this->pdo->exec('ALTER TABLE '.$this->quoteIdentifier($table).' '.implode(', ', $additions));
            }
        });
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (strlen($identifier) > 64 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new InvalidSchema('SQL identifiers must contain at most 64 ASCII letters, digits or underscores.');
        }

        return '`'.$identifier.'`';
    }

    private function sqlType(string $type): string
    {
        return match ($type) {
            'string' => 'longtext',
            'integer' => 'bigint',
            'number' => 'double',
            'boolean' => 'tinyint',
            default => throw new InvalidSchema('Unsupported SQL field type.'),
        };
    }

    private function column(string $field, string $type, bool $required): string
    {
        $sqlType = $type === 'boolean' ? 'TINYINT(1)' : strtoupper($this->sqlType($type));

        return $this->quoteIdentifier($field).' '.$sqlType.($required ? ' NOT NULL' : ' NULL');
    }
}
