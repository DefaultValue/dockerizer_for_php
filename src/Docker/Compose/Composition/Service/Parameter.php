<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\Service;

use DefaultValue\Dockerizer\Console\CommandOption\OptionDefinitionInterface;

/**
 * Parameter and options meaning example:
 * - {{composer_version}} - nothing special here
 * - {{domains:0}} - get the first value from array (space-separated values)
 * - {{domains| }} - implode the parameter using ' ' (empty string) as a separator
 * - {{domains|,|'}} - implode the parameter using ' ' (empty string) as a separator, enclose values with single quotes
 * - {{domains:1|,|'}} - not a valid value (ambiguous shortcode)
 */
class Parameter
{
    // @TODO: get parameters from all services and mounted files
    /**
     * @param string $content
     * @param array $existingParameters
     * @return string[]
     */
    public function getMissedParameters(string $content, array $existingParameters = []): array
    {
//        return ['domains:0', 'domains| |', 'domains|,|`', 'composer_version'];
        return ['domains:0', 'domains| |', 'domains|,|`'];
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
            $search[] = "{{$parameterDefinition}}";
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
        $parameterDefinition = explode('|', $parameterDefinitionString);
        $code = $parameterDefinition[0];
        $valueIndex = null;

        if (str_contains($code, ':')) {
            [$code, $valueIndex] = explode(':', $parameterDefinition[0]);
            $valueIndex = (int) $valueIndex;
        }

        if (!isset($parameters[$code])) {
            throw new \InvalidArgumentException("Can't generate Docker composition! Parameter '$code' is missed.");
        }

        $value = $parameters[$code];
        $separator = $parameterDefinition[1] ?? OptionDefinitionInterface::VALUE_SEPARATOR;
        $enclosure = $parameterDefinition[2] ?? null;

        if (is_array($value) && !is_null($valueIndex)) {
            return $value[$valueIndex];
        }

        if ($enclosure) {
            $value = array_map(static fn($item) => sprintf('%s%s%s', $enclosure, $item, $enclosure), $value);
        }

        if (is_array($value)) {
            return implode($separator, $value);
        }

        return $value;
    }
}
