<?php

declare(strict_types=1);

namespace Obens\Sdk;

final class Mapper
{
    /** A fixed value for every record. Plain strings in a spec are paths into the record. */
    public static function constant(mixed $value): Constant
    {
        return new Constant($value);
    }

    /**
     * Builds a mapper (your record -> what the platform expects) from a spec.
     *
     * Each entry is a string (a dotted path into your record: array key, property or ArrayAccess), a Closure
     * computed from the record, `Mapper::constant(x)` for a fixed value, or a number / bool taken as is. Dotted keys
     * build nested arrays and `null` results are left out, so an update never blanks a field.
     *
     * @param array<string,mixed> $spec
     */
    public static function create(array $spec): \Closure
    {
        return static function (mixed $record) use ($spec): array {
            $out = [];
            foreach ($spec as $key => $source) {
                if ($source instanceof Constant) {
                    $value = $source->value;
                } elseif ($source instanceof \Closure) {
                    $value = $source($record);
                } elseif (is_string($source)) {
                    $value = self::read($record, $source);
                } else {
                    $value = $source;
                }
                if ($value === null) {
                    continue;
                }
                $parts = explode('.', (string) $key);
                $last = array_pop($parts);
                $target = &$out;
                foreach ($parts as $part) {
                    if (!isset($target[$part]) || !is_array($target[$part])) {
                        $target[$part] = [];
                    }
                    $target = &$target[$part];
                }
                $target[$last] = $value;
                unset($target);
            }
            return $out;
        };
    }

    private static function read(mixed $record, string $path): mixed
    {
        $value = $record;
        foreach (explode('.', $path) as $key) {
            if ($value === null) {
                return null;
            }
            if (is_array($value) || $value instanceof \ArrayAccess) {
                $value = $value[$key] ?? null;
            } elseif (is_object($value)) {
                $value = $value->$key ?? null;
            } else {
                return null;
            }
        }
        return $value;
    }
}
