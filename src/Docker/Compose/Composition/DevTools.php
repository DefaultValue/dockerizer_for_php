<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

use Symfony\Component\Yaml\Yaml;

class DevTools extends \DefaultValue\Dockerizer\Filesystem\ProcessibleFile\AbstractFile implements
    \DefaultValue\Dockerizer\DependencyInjection\DataTransferObjectInterface
{
    /**
     * @TODO: to be implemented
     *
     * @param array{} $data
     * @return void
     */
    protected function validate(array $data): void
    {
        Yaml::parseFile($this->getFileInfo()->getRealPath());
    }
}
