<?php
declare(strict_types=1);

namespace CakeMcp\Server;

/**
 * Base class for tools: argument readers that throw ToolError with a clear message, and result
 * builders that return both readable text and `structuredContent`.
 *
 * ```php
 * class CountMembers extends Tool
 * {
 *     public function definition(): array
 *     {
 *         return [
 *             'name' => 'count_members',
 *             'description' => 'Number of active members.',
 *             'inputSchema' => self::schema([]),
 *             'annotations' => self::readOnly(),
 *         ];
 *     }
 *
 *     public function call(array $arguments, McpContext $context): array
 *     {
 *         return $this->result(['count' => 42]);
 *     }
 * }
 * ```
 */
abstract class Tool implements ToolInterface
{
    /**
     * An object input schema.
     *
     * @param array<string, array<string, mixed>> $properties Property schemas.
     * @param array<string> $required Required property names.
     * @return array<string, mixed>
     */
    protected static function schema(array $properties, array $required = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Annotations for a tool that only reads.
     *
     * @return array<string, bool>
     */
    protected static function readOnly(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    /**
     * Annotations for a tool that changes data.
     *
     * @param bool $destructive Whether it can remove or hide something, or overwrite content.
     * @param bool $idempotent Whether repeating the same call has no further effect.
     * @return array<string, bool>
     */
    protected static function writes(bool $destructive = false, bool $idempotent = false): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => $destructive, 'idempotentHint' => $idempotent, 'openWorldHint' => false];
    }

    /**
     * A successful result: the data as structuredContent, and as text for clients that only
     * read text. Lists are wrapped as {"items": [...]}, because structuredContent is an object.
     *
     * @param array<mixed> $data Result data.
     * @param string|null $summary Optional sentence put before the JSON text.
     * @return array<string, mixed>
     */
    protected function result(array $data, ?string $summary = null): array
    {
        if ($data !== [] && array_is_list($data)) {
            $data = ['items' => $data];
        }
        $json = (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return [
            'content' => [['type' => 'text', 'text' => $summary === null ? $json : $summary . "\n\n" . $json]],
            'structuredContent' => $data === [] ? new \stdClass() : $data,
        ];
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function string(array $arguments, string $name, bool $required = false, int $maxLength = 0): ?string
    {
        $value = $arguments[$name] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw new ToolError("`$name` is required.");
            }

            return null;
        }
        if (!is_string($value)) {
            throw new ToolError("`$name` must be a string.");
        }
        if ($maxLength > 0 && mb_strlen($value) > $maxLength) {
            throw new ToolError("`$name` is longer than $maxLength characters.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function int(array $arguments, string $name, bool $required = false, ?int $min = null, ?int $max = null): ?int
    {
        $value = $arguments[$name] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw new ToolError("`$name` is required.");
            }

            return null;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $value = (int)$value;
        }
        if (!is_int($value)) {
            throw new ToolError("`$name` must be an integer.");
        }
        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            throw new ToolError("`$name` must be between " . ($min ?? '-∞') . ' and ' . ($max ?? '∞') . '.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function bool(array $arguments, string $name, ?bool $default = null): ?bool
    {
        $value = $arguments[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_bool($value)) {
            throw new ToolError("`$name` must be true or false.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string> $allowed Allowed values.
     */
    protected function enum(array $arguments, string $name, array $allowed, ?string $default = null): ?string
    {
        $value = $arguments[$name] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ToolError("`$name` must be one of: " . implode(', ', $allowed) . '.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     * @return array<int>
     */
    protected function intList(array $arguments, string $name, int $maxItems = 100): array
    {
        $value = $arguments[$name] ?? null;
        if (!is_array($value) || $value === []) {
            throw new ToolError("`$name` must be a non-empty array of integers.");
        }
        if (count($value) > $maxItems) {
            throw new ToolError("`$name` may hold at most $maxItems items.");
        }
        $ids = [];
        foreach ($value as $item) {
            if (is_string($item) && preg_match('/^\d+$/', $item)) {
                $item = (int)$item;
            }
            if (!is_int($item)) {
                throw new ToolError("`$name` must contain only integers.");
            }
            $ids[] = $item;
        }

        return array_values(array_unique($ids));
    }
}
