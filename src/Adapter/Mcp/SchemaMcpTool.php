<?php
declare(strict_types=1);

namespace App\Adapter\Mcp;

use App\Core\Service\SchemaManager;

final readonly class SchemaMcpTool
{
    public function __construct(private SchemaManager $schemas)
    {
    }

    public function definitions(): array
    {
        return [$this->definition(), $this->definition('update')];
    }

    public function definition(string $method = 'create'): array
    {
        if (!in_array($method, ['create', 'update'], true)) {
            throw new \InvalidArgumentException('Unsupported schema method.');
        }
        return [
            'name' => $method.'_dynamic_schema',
            'description' => $method === 'create' ? 'Create a named schema and its MySQL table. Duplicate names fail. Supported fields: string, integer, number, boolean. Schema keywords: type, properties, required, additionalProperties (false).' : 'Update an existing schema by adding optional fields. Supply the entire schema including existing fields. Removal, type/nullability changes and new required fields fail.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'pattern' => '^[A-Z][A-Za-z0-9_]{0,63}$'],
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['const' => 'object'],
                            'properties' => [
                                'type' => 'object',
                                'minProperties' => 1,
                                'propertyNames' => ['pattern' => '^[A-Za-z_][A-Za-z0-9_]{0,63}$'],
                                'additionalProperties' => [
                                    'type' => 'object',
                                    'properties' => ['type' => ['enum' => ['string', 'integer', 'number', 'boolean']]],
                                    'required' => ['type'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'required' => ['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => true],
                            'additionalProperties' => ['const' => false],
                        ],
                        'required' => ['type', 'properties'],
                        'additionalProperties' => false,
                    ],
                ],
                'required' => ['name', 'schema'],
                'additionalProperties' => false,
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => $method === 'update', 'openWorldHint' => false],
        ];
    }

    public function execute(array $arguments, string $method = 'create'): array
    {
        if (!in_array($method, ['create', 'update'], true)) {
            throw new \InvalidArgumentException('Unsupported schema method.');
        }
        try {
            $schema = match ($method) {
                'create' => $this->schemas->create($arguments)->toArray(),
                'update' => $this->schemas->update($arguments)->toArray(),
            };

            return ['content' => [['type' => 'text', 'text' => json_encode($schema, JSON_THROW_ON_ERROR)]], 'structuredContent' => $schema, 'isError' => false];
        } catch (\DomainException $error) {
            return ['content' => [['type' => 'text', 'text' => $error->getMessage()]], 'isError' => true];
        }
    }
}
