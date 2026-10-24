<?php
declare(strict_types=1);

namespace App\Core\Model;

final readonly class DynamicEntity
{
    /** @param array<string, mixed> $values */
    public function __construct(public SchemaDefinition $definition, public array $values)
    {
    }
}
