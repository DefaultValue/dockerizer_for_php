<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

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
