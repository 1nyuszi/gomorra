<?php
declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Exception\InvalidSchema;
use App\Core\Model\DynamicEntity;
use App\Core\Model\SchemaDefinition;

/** Deliberately small JSON Schema subset: objects with typed scalar fields. */
final class JsonSchemaParser
{
    public function parse(array $input): SchemaDefinition
    {
        if (array_diff(array_keys($input), ['name', 'schema']) !== []) {
            throw new InvalidSchema('Only name and schema are accepted.');
        }
        $name = $input['name'] ?? null;
        $schema = $input['schema'] ?? null;
        if (!is_string($name) || preg_match('/^[A-Z][A-Za-z0-9_]{0,63}$/D', $name) !== 1) {
            throw new InvalidSchema('name must be a PascalCase identifier of 1–64 characters.');
        }
        if (!is_array($schema) || ($schema['type'] ?? null) !== 'object') {
            throw new InvalidSchema('schema.type must be object.');
        }
        if (array_diff(array_keys($schema), ['type', 'properties', 'required', 'additionalProperties']) !== []) {
            throw new InvalidSchema('Unsupported schema keyword.');
        }
        $properties = $schema['properties'] ?? null;
        if ($properties instanceof \stdClass) {
            $properties = (array) $properties;
        }
        if (!is_array($properties) || $properties === [] || array_is_list($properties)) {
            throw new InvalidSchema('properties must be a nonempty object.');
        }
        foreach ($properties as $field => $metadata) {
            if (!is_string($field) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $field) !== 1) {
                throw new InvalidSchema('Invalid field identifier.');
            }
            if (!is_array($metadata) || array_keys($metadata) !== ['type'] || !in_array($metadata['type'], ['string', 'integer', 'number', 'boolean'], true)) {
                throw new InvalidSchema(sprintf('Field %s must declare only a scalar type: string, integer, number or boolean.', $field));
            }
        }
        $required = $schema['required'] ?? [];
        if (!is_array($required) || !array_is_list($required)) {
            throw new InvalidSchema('required must be an array of field names.');
        }
        foreach ($required as $field) {
            if (!is_string($field) || !array_key_exists($field, $properties)) {
                throw new InvalidSchema('Every required field must exist in properties.');
            }
        }
        if (count(array_unique($required)) !== count($required)) {
            throw new InvalidSchema('required must contain unique names.');
        }
        if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] !== false) {
            throw new InvalidSchema('Only additionalProperties: false is supported.');
        }
        $schema['properties'] = $properties;
        $schema['required'] = $required;
        $schema['additionalProperties'] = false;

        return new SchemaDefinition($name, $schema);
    }

    public function hydrate(SchemaDefinition $definition, array $values): DynamicEntity
    {
        $schema = $definition->schema;
        foreach ($schema['required'] as $field) {
            if (!array_key_exists($field, $values)) {
                throw new InvalidSchema(sprintf('Missing required field: %s.', $field));
            }
        }
        foreach ($values as $field => $value) {
            $type = $schema['properties'][$field]['type'] ?? null;
            $valid = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || (is_float($value) && is_finite($value)),
                'boolean' => is_bool($value),
                default => false,
            };
            if (!$valid) {
                throw new InvalidSchema(sprintf('Unknown field or invalid value: %s.', $field));
            }
        }

        return new DynamicEntity($definition, $values);
    }
}
