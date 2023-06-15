<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose;

use DefaultValue\Dockerizer\Docker\Compose;
use Symfony\Component\Finder\Finder;

/**
 * Get collection of docker-compose files in the project
 */
class Collection
{
    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition  $composition
     * @param \DefaultValue\Dockerizer\Docker\Compose $dockerCompose
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Docker\Compose $dockerCompose,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
    ) {
    }

    /**
     * @param string $projectRoot
     * @param string $compositionFilter
     * @return Compose[]
     */
    public function getList(string $projectRoot = '', string $compositionFilter = ''): array
    {
        $projectRoot = $projectRoot ?: (string) getcwd() . DIRECTORY_SEPARATOR;
        $dockerComposeCollection = [];

        // In case we're not in the dockerized project and trying to clean it up
        if (!$this->filesystem->isDir($this->composition->getDockerizerDirInProject($projectRoot))) {
            return $dockerComposeCollection;
        }

        $finder = Finder::create()->in($this->composition->getDockerizerDirInProject($projectRoot))->depth(0);

        foreach ($finder->directories() as $dockerizerDir) {
            try {
                // A primitive way to filter compositions inside the project by some substring - for example,
                // environment name if present
                if (
                    $compositionFilter
                    && !str_contains($dockerizerDir->getFilename(), $compositionFilter)
                ) {
                    continue;
                }

                $dockerComposeCollection[$dockerizerDir->getFilename()] = $this->dockerCompose->initialize(
                    $dockerizerDir->getRealPath()
                );
            } catch (CompositionFilesNotFoundException) {
                // Do nothing if the folder does not contain valid files. Maybe this is just some test dir
            }
        }

        return $dockerComposeCollection;
    }
}
