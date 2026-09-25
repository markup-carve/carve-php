<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use stdClass;

/**
 * Restore JSON object identity inside extension-owned payloads.
 */
final class OpaqueJsonPayloads
{
    /**
     * @param array<string, mixed> $decoded
     * @param mixed $raw
     *
     * @return array<string, mixed>
     */
    public static function restore(array $decoded, mixed $raw): array
    {
        $restored = self::walk($decoded, $raw);

        return is_array($restored) ? $restored : $decoded;
    }

    private static function walk(mixed $decoded, mixed $raw): mixed
    {
        if (!is_array($decoded) || (!is_array($raw) && !$raw instanceof stdClass)) {
            return $decoded;
        }

        if ($raw instanceof stdClass) {
            if (($decoded['type'] ?? null) === 'block_extension' && ($raw->payload ?? null) instanceof stdClass) {
                $decoded['payload'] = get_object_vars($raw->payload);
            }
            if (
                ($decoded['type'] ?? null) === 'carveBlockExtension'
                && is_array($decoded['attrs'] ?? null)
                && ($raw->attrs ?? null) instanceof stdClass
                && ($raw->attrs->payload ?? null) instanceof stdClass
            ) {
                $decoded['attrs']['payload'] = get_object_vars($raw->attrs->payload);
            }
            $raw = get_object_vars($raw);
        }

        foreach ($decoded as $key => $value) {
            if ($key === 'attrs' || $key === 'payload' || !array_key_exists($key, $raw)) {
                continue;
            }
            $decoded[$key] = self::walk($value, $raw[$key]);
        }

        return $decoded;
    }
}
