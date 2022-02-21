<?php

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

    private const PARAMETER_PROCESSOR_ARGUMENT_DELIMITER = ':';

    /**
     * @param string $content
     * @return array
     */
    public function extractParameters(string $content): array
    {
        preg_match_all('/{{(.*)}}/U', $content, $matches);

        return $matches[1];
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
            $search[] = '{{' . $parameterDefinition . '}}';
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

        foreach ($parameterDefinitions as $processorDefinition) {
            $value = $this->processValue($value, $processorDefinition);
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
     * @param string $origProcessorDefinition
     * @return array|string
     */
    private function processValue(mixed $value, string $origProcessorDefinition): mixed
    {
        $processorDefinition = explode(self::PARAMETER_PROCESSOR_ARGUMENT_DELIMITER, $origProcessorDefinition);

        try {
            // Value always goes first
            $processor = match ($processorDefinition[0]) {
                // For possible future use
                'explode' => static function(string $value, string $separator): array {
                    return explode($separator, $value);
                },
                'implode' => static function(array $value, string $separator): string {
                    return implode($separator, array_filter($value));
                },
                'first' => static function(string $value, string $separator): string {
                    return (string) array_filter(explode($separator, $value))[0];
                },
                'enclose' => static function(mixed $value, string $enclosure): array|string {
                    return is_array($value)
                        ? array_map(static function(mixed $value) use ($enclosure) {
                            return $enclosure . $value . $enclosure;
                        }, array_filter($value))
                        : $enclosure . $value . $enclosure;
                },
//                'get' => static function(array $value, int $index) {
//                    return $value[$index];
//                },
                'replace' => static function(string $value, string $search, string $replace): string {
                    return str_replace($search, $replace, $value);
                }
            };
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException("{$e->getMessage()} for parameter $origProcessorDefinition");
        }

        return match ($processorDefinition[0]) {
            // For possible future use
            'explode',
            'implode',
            'first',
            'enclose' => $processor($value, (string) ($processorDefinition[1] ?? ' ')),
//            'get' => $processor($value, (int) $processorDefinition[1]),
            'replace' => $processor($value, (string) $processorDefinition[1], (string) $processorDefinition[2])
        };
    }
}
