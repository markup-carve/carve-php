<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use ReflectionObject;

/**
 * A refused `+` is a plain letter (CARVE-P9-085): it reads as a private byte through every
 * reader, so it can have no effect a letter lacks, and is restored once the tree is built.
 *
 * @internal
 */
final class PlusLetter
{
    /**
     * A byte the source does not hold. One byte, so every source column and offset stands.
     */
    public static function for(string $source): string
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31] as $byte) {
            if (!str_contains($source, chr($byte))) {
                return chr($byte);
            }
        }
        $code = 0xE100;
        while (str_contains($source, mb_chr($code, 'UTF-8'))) {
            $code++;
        }

        return mb_chr($code, 'UTF-8');
    }

    /**
     * @param string $letter
     * @param object $root
     */
    public static function restore(object $root, string $letter): void
    {
        $seen = [];
        $pending = [$root];
        while ($pending !== []) {
            $object = array_pop($pending);
            $id = spl_object_id($object);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $reflection = new ReflectionObject($object);
            do {
                foreach ($reflection->getProperties() as $property) {
                    if ($property->isStatic() || !$property->isInitialized($object)) {
                        continue;
                    }
                    $value = $property->getValue($object);
                    if (is_object($value) && !str_starts_with($value::class, 'MarkupCarve\\')) {
                        continue;
                    }
                    if (is_string($value) && !str_contains($value, $letter)) {
                        continue;
                    }
                    if (is_string($value) || is_array($value)) {
                        $restored = self::restoreValue($value, $letter, $pending);
                        if ($restored !== $value && !$property->isReadOnly()) {
                            $property->setValue($object, $restored);
                        }
                    } elseif (is_object($value)) {
                        $pending[] = $value;
                    }
                }
                $reflection = $reflection->getParentClass();
            } while ($reflection !== false);
        }
    }

    /**
     * @param mixed $value
     * @param string $letter
     * @param array<object> $pending
     *
     * @return mixed
     */
    private static function restoreValue(mixed $value, string $letter, array &$pending): mixed
    {
        if (is_string($value)) {
            return str_replace($letter, '+', $value);
        }
        if (is_object($value)) {
            if (str_starts_with($value::class, 'MarkupCarve\\')) {
                $pending[] = $value;
            }

            return $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $restored = [];
        foreach ($value as $key => $entry) {
            $restored[is_string($key) ? str_replace($letter, '+', $key) : $key] = self::restoreValue($entry, $letter, $pending);
        }

        return $restored;
    }
}
