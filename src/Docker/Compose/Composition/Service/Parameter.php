<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\Service;

/**
 * Parameter and options meaning example:
 * - {{composer_version}} - nothing special here
 * - {{domains:0}} - get the first value from array (space-separated values)
 * - {{domains|implode: }} - implode the parameter using ' ' (empty string) as a separator
 * - {{domains|enclose:'|implode:,}} - implode the parameter using ',' (comma) as a separator, enclose values with single quotes
 *
 * array_slice:0:1
 */
class Parameter
{
    private const PARAMETER_DEFINITION_DELIMITER = '|';

    private const PARAMETER_PROCESSOR_ARGUMENT_DELIMITER = ':';

    // @TODO: get parameters from all services and mounted files
    /**
     * @param string $content
     * @param array $existingParameters
     * @return string[]
     */
    public function getMissedParameters(string $content, array $existingParameters = []): array
    {
        // @TODO: validate definitions
        return [
            'domains',
            'domains|explode|get:0',
            'domains|explode|enclose:`|implode:,',
            'composer_version',
            'php_version',
            'environment'
        ];
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

        foreach ($this->getMissedParameters($content) as $parameterDefinition) {
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
    public function extractValue(string $parameterDefinitionString, array $parameters): string
    {
        $parameterDefinitions = explode(self::PARAMETER_DEFINITION_DELIMITER, $parameterDefinitionString);
        $parameterName = array_shift($parameterDefinitions);

        if (!isset($parameters[$parameterName])) {
            throw new \InvalidArgumentException(
                "Can't generate Docker composition! Parameter '$parameterName' is missed."
            );
        }

        $value = $parameters[$parameterName];

        foreach ($parameterDefinitions as $processorDefinition) {
            $value = $this->processValue($value, $processorDefinition);
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
    public function processValue(mixed $value, string $origProcessorDefinition): mixed
    {
        $processorDefinition = explode(self::PARAMETER_PROCESSOR_ARGUMENT_DELIMITER, $origProcessorDefinition);

        try {
            // Value always goes first
            $processor = match ($processorDefinition[0]) {
                'explode' => static function(string $value, string $separator): array {
                    return explode($separator, $value);
                },
                'implode' => static function(array $value, string $separator): string {
                    return implode($separator, $value);
                },
                'enclose' => static function(mixed $value, string $enclosure): array|string {
                    return is_array($value)
                        ? array_map(static function(mixed $value) use ($enclosure) {
                            return $enclosure . $value . $enclosure;
                        }, $value)
                        : $enclosure . $value . $enclosure;
                },
                'get' => static function(array $value, int $index) {
                    return $value[$index];
                },
                'replace' => static function(string $value, string $search, string $replace): string {
                    return str_replace($search, $replace, $value);
                }
            };
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException("{$e->getMessage()} for parameter $origProcessorDefinition");
        }

        return match ($processorDefinition[0]) {
            'explode',
            'implode',
            'enclose' => $processor($value, (string) ($processorDefinition[1] ?? '')),
            'get' => $processor($value, (int) $processorDefinition[1]),
            'replace' => $processor($value, (int) $processorDefinition[1], (int) $processorDefinition[2])
        };
    }
}
