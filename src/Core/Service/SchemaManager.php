<?php
declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Database\DynamicEntityManager;
use App\Core\Exception\InvalidSchema;
use App\Core\Exception\SchemaAlreadyExists;
use App\Core\Model\SchemaDefinition;
use App\Core\Port\SchemaRepository;

final readonly class SchemaManager
{
    public function __construct(private JsonSchemaParser $parser, private SchemaRepository $repository, private DynamicEntityManager $database)
    {
    }

    public function create(array $metadata): SchemaDefinition
    {
        $definition = $this->parser->parse($metadata);
        $this->database->withSchemaLock($definition->name, function () use ($definition): void {
            if ($this->repository->find($definition->name) !== null) {
                throw new SchemaAlreadyExists(sprintf('Schema %s already exists.', $definition->name));
            }
            $this->database->synchronize($definition);
            $this->repository->add($definition);
        });

        return $definition;
    }

    public function update(array $metadata): SchemaDefinition
    {
        $definition = $this->parser->parse($metadata);
        $this->database->withSchemaLock($definition->name, function () use ($definition): void {
            $existing = $this->repository->find($definition->name);
            if ($existing === null || $existing->name !== $definition->name) {
                throw new InvalidSchema('An update requires an existing schema with the exact same name.');
            }
            $this->database->synchronize($definition);
            $this->repository->replace($definition);
        });

        return $definition;
    }
}
