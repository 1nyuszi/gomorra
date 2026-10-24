<?php
declare(strict_types=1);

namespace App\Core\Model;

final readonly class SchemaDefinition
{
    /** @param array<string, mixed> $schema */
    public function __construct(public string $name, public array $schema)
    {
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'schema' => $this->schema];
    }
}
