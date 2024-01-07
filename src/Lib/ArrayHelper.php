<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Lib;

class ArrayHelper
{
    /**
     * Recursively replace key-value pairs, but merge indexed arrays
     *
     * @param array $inputArrays
     * @return array
     */
    public static function arrayMergeReplaceRecursive(...$inputArrays): array
    {
        $merged = array_shift($inputArrays);

        foreach ($inputArrays as $array) {
            foreach ($array as $key => $value) {
                if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                    if (array_keys($value) === range(0, count($value) - 1)) {
                        // Numeric-indexed array: merge
                        $merged[$key] = array_merge($merged[$key], $value);
                    } else {
                        // Associative array: recursive replace/merge
                        $merged[$key] = self::arrayMergeReplaceRecursive($merged[$key], $value);
                    }
                } else {
                    // Replace the value
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }
}
