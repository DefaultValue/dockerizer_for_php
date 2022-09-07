<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition;

use Symfony\Component\Yaml\Yaml;

class DevTools extends \DefaultValue\Dockerizer\Filesystem\ProcessibleFile\AbstractFile implements
    \DefaultValue\Dockerizer\DependencyInjection\DataTransferObjectInterface
{
    /**
     * Throw exception if not able to parse the file
     */
    protected function validate(array $data): void
    {
        Yaml::parseFile($this->getFileInfo()->getRealPath());
    }
}
