<?php

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation;

interface ModifierInterface
{
    /**
     * Modify YAML and provide Readme if possible
     *
     * @param array $yamlContent
     * @param array $readme
     */
    public function modify(array &$yamlContent, array &$readme): void;
}