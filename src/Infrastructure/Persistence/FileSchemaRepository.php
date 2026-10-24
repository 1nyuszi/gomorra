<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Exception\SchemaAlreadyExists;
use App\Core\Exception\InvalidSchema;
use App\Core\Model\SchemaDefinition;
use App\Core\Port\SchemaRepository;

/** Local reference persistence; replace with a transactional database for production. */
final readonly class FileSchemaRepository implements SchemaRepository
{
    public function __construct(private string $path)
    {
    }

    public function add(SchemaDefinition $definition): void
    {
        $this->write($definition, false);
    }

    public function replace(SchemaDefinition $definition): void
    {
        $this->write($definition, true);
    }

    public function find(string $name): ?SchemaDefinition
    {
        if (!is_file($this->path)) {
            return null;
        }
        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot read schema storage.');
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException('Cannot lock schema storage.');
            }
            $json = stream_get_contents($handle);
            if ($json === false) {
                throw new \RuntimeException('Cannot read schema storage.');
            }
            $schemas = $json === '' ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($schemas)) {
                throw new \RuntimeException('Corrupt schema storage.');
            }
            foreach ($schemas as $existingName => $metadata) {
                if (strcasecmp($existingName, $name) === 0) {
                    return new SchemaDefinition($existingName, $metadata['schema']);
                }
            }

            return null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function write(SchemaDefinition $definition, bool $replace): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create schema storage directory.');
        }
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open schema storage.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock schema storage.');
            }
            $json = stream_get_contents($handle);
            if ($json === false) {
                throw new \RuntimeException('Cannot read schema storage.');
            }
            $schemas = $json === '' ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($schemas)) {
                throw new \RuntimeException('Corrupt schema storage.');
            }
            if (!$replace && array_any(array_keys($schemas), static fn (string $name): bool => strcasecmp($name, $definition->name) === 0)) {
                throw new SchemaAlreadyExists(sprintf('Schema %s already exists.', $definition->name));
            }
            if ($replace && !array_key_exists($definition->name, $schemas)) {
                throw new InvalidSchema('Cannot update a schema that does not exist.');
            }
            $schemas[$definition->name] = $definition->toArray();
            $encoded = json_encode($schemas, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new \RuntimeException('Cannot persist schema.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
