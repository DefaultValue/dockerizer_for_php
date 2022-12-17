<?php

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
