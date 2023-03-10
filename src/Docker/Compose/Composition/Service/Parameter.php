<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\Service;

/**
 * Adding parameters and processing parameter value:
 * - {{composer_version}} - nothing special here
 * - {{domains|first}} - get the first value from array (space-separated values)
 * - {{domains|first|replace:.:-}} - get the first value and replace `.` (dot) with `-` (dash)
 * - {{domains|enclose:'}} - enclose a single value or all array values with quotes
 * - {{domains|explode:,}} - explode value to array, use ',' (comma) as a separator
 * - {{domains|implode:,}} - implode array to string, use ',' (comma) as a separator
 *
 * array_slice:0:1
 */
class Parameter
{
    private const PARAMETER_DEFINITION_DELIMITER = '|';

    private const PARAMETER_MODIFIER_ARGUMENT_DELIMITER = ':';

    /**
     * @param string $content
     * @return array
     */
    public function extractParameters(string $content): array
    {
        preg_match_all('/{{(.*)}}/U', $content, $matches);

        return array_unique($matches[1]);
    }

    /**
     * @param string $parameterDefinitionString
     * @return string
     */
    public function getNameFromDefinition(string $parameterDefinitionString): string
    {
        return explode(self::PARAMETER_DEFINITION_DELIMITER, $parameterDefinitionString)[0];
    }

    /**
     * @param string $content
     * @param array $parameters
     * @return string
     */
    public function apply(string $content, array $parameters): string
    {
        $search = [];
        $replace = [];

        foreach ($this->extractParameters($content) as $parameterDefinition) {
            // Handle array values or dynamic key names first
            if (str_contains($parameterDefinition, 'remove_single_quotes_wrapper')) {
                $search[] = sprintf('\'{{%s}}\'', $parameterDefinition);
            } elseif (str_contains($parameterDefinition, 'remove_double_quotes_wrapper')) {
                $search[] = sprintf('"{{%s}}"', $parameterDefinition);
            } else {
                $search[] = '{{' . $parameterDefinition . '}}';
            }

            $replace[] = $this->extractValue($parameterDefinition, $parameters);
        }

        return str_replace($search, $replace, $content);
    }

    /**
     * @param string $parameterDefinitionString
     * @param array $parameters
     * @return string
     */
    private function extractValue(string $parameterDefinitionString, array $parameters): string
    {
        $parameterDefinitions = explode(self::PARAMETER_DEFINITION_DELIMITER, $parameterDefinitionString);
        $parameter = array_shift($parameterDefinitions);

        if (!isset($parameters[$parameter])) {
            throw new \InvalidArgumentException(
                "Can't generate Docker composition! Parameter '$parameter' is missed."
            );
        }

        $value = $parameters[$parameter];

        foreach ($parameterDefinitions as $parameterDefinition) {
            // Special case related to the outer content. Wrapping the definition may be required to keep the YAML valid
            if (
                $parameterDefinition === 'remove_single_quotes_wrapper'
                || $parameterDefinition === 'remove_double_quotes_wrapper'
            ) {
                continue;
            }

            $value = $this->processValue($value, $parameterDefinition);
        }

        if (is_numeric($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                "Parameter definition does not reduce final value to string: $parameterDefinitionString"
            );
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @param string $origParameterDefinition
     * @return array|string
     */
    private function processValue(mixed $value, string $origParameterDefinition): mixed
    {
        $modifierDefinition = explode(self::PARAMETER_MODIFIER_ARGUMENT_DELIMITER, $origParameterDefinition);

        try {
            // Value always goes first
            $modifier = match ($modifierDefinition[0]) {
                // For possible future use
                'explode' => static function (string $value, string $separator): array {
                    return explode($separator, $value);
                },
                'implode' => static function (array $value, string $separator): string {
                    return implode($separator, array_filter($value));
                },
                'first' => static function (string $value, string $separator): string {
                    return (string) array_filter(explode($separator, $value))[0];
                },
                'enclose' => static function (mixed $value, string $enclosure): array|string {
                    return is_array($value)
                        ? array_map(static function (mixed $value) use ($enclosure) {
                            return $enclosure . $value . $enclosure;
                        }, array_filter($value))
                        : $enclosure . $value . $enclosure;
                },
//                'get' => static function(array $value, int $index) {
//                    return $value[$index];
//                },
                'replace' => static function (string $value, string $search, string $replace): string {
                    return str_replace($search, $replace, $value);
                },
                // We need to know the indentation level to properly generate YAML array
                // A few arrays in the same file may have different indentation levels
                'to_yaml_array' => static function (array $items, int $indent): string {
                    if (!$indent) {
                        throw new \InvalidArgumentException(
                            'Can\'t generate Docker composition! Indentation is not specified.'
                        );
                    }

                    $yamlArrayItems = array_merge(
                        [array_shift($items)],
                        array_map(static fn (string $item) => str_repeat(' ', $indent) . '- ' . $item, $items)
                    );

                    return implode(PHP_EOL, $yamlArrayItems);
                },
            };
        } catch (\UnhandledMatchError $e) {
            throw new \InvalidArgumentException('Unknown parameter modifier: ' . $modifierDefinition[0]);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException("{$e->getMessage()} for parameter $origParameterDefinition");
        }

        return match ($modifierDefinition[0]) {
            // For possible future use
            'explode',
            'implode',
            'first',
            'enclose' => $modifier($value, (string) ($modifierDefinition[1] ?? ' ')),
//            'get' => $modifier($value, (int) $processorDefinition[1]),
            'replace' => $modifier($value, (string) $modifierDefinition[1], (string) $modifierDefinition[2]),
            'to_yaml_array' => $modifier($value, (int) $modifierDefinition[1])
        };
    }
}
