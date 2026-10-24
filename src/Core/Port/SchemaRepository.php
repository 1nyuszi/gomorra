<?php
declare(strict_types=1);

namespace App\Core\Port;

use App\Core\Model\SchemaDefinition;

interface SchemaRepository
{
    /** Atomically insert, throwing SchemaAlreadyExists on duplicate names. */
    public function add(SchemaDefinition $definition): void;

    public function find(string $name): ?SchemaDefinition;

    public function replace(SchemaDefinition $definition): void;
}
