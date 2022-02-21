<?php

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation;

interface ModifierInterface
{
    /**
     * Modify YAML and provide Readme if possible
     *
     * @param array $yamlContent
     * @param array $readme
     * @param string $projectRoot
     */
    public function modify(array &$yamlContent, array &$readme, string $projectRoot, string $dockerComposeDir): void;

    /**
     * @return int
     */
    public function getSortOrder(): int;
}