<?php

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation;

interface ModifierInterface
{
    /**
     * Modify YAML and provide Readme if possible
     *
     * @param ModificationContext $modificationContext
     */
    public function modify(ModificationContext $modificationContext): void;

    /**
     * @return int
     */
    public function getSortOrder(): int;
}
