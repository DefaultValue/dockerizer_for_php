<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation;

use DefaultValue\Dockerizer\DependencyInjection\DataTransferObjectInterface;

class ModificationContext implements DataTransferObjectInterface
{
    private string $dockerComposeDir;

    private string $projectRoot;

    private array $compositionYaml;

    private array $devToolsYaml;

    private array $readme = [];

    /**
     * @return string
     */
    public function getDockerComposeDir(): string
    {
        return $this->dockerComposeDir;
    }

    /**
     * @param string $dockerComposeDir
     * @return ModificationContext
     */
    public function setDockerComposeDir(string $dockerComposeDir): ModificationContext
    {
        if (isset($this->projectRoot)) {
            throw new \RuntimeException('Project root can\'t be changed!');
        }

        $this->dockerComposeDir = $dockerComposeDir;

        return $this;
    }

    /**
     * @return string
     */
    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * @param string $projectRoot
     * @return ModificationContext
     */
    public function setProjectRoot(string $projectRoot): ModificationContext
    {
        if (isset($this->projectRoot)) {
            throw new \RuntimeException('Project root can\'t be changed!');
        }

        $this->projectRoot = $projectRoot;

        return $this;
    }

    /**
     * Get composition YAML as array before it is actually dumped
     *
     * @return array
     */
    public function getCompositionYaml(): array
    {
        return $this->compositionYaml;
    }

    /**
     * @param array $compositionYaml
     * @return ModificationContext
     */
    public function setCompositionYaml(array $compositionYaml): ModificationContext
    {
        $this->compositionYaml = $compositionYaml;

        return $this;
    }

    /**
     * @return array
     */
    public function getDevToolsYaml(): array
    {
        return $this->devToolsYaml;
    }

    /**
     * @param array $devToolsYaml
     * @return ModificationContext
     */
    public function setDevToolsYaml(array $devToolsYaml): ModificationContext
    {
        $this->devToolsYaml = $devToolsYaml;

        return $this;
    }

    /**
     * @return array
     */
    public function getReadme(): array
    {
        return $this->readme;
    }

    /**
     * @param int $index
     * @param string $readmeMd
     * @return ModificationContext
     */
    public function appendReadme(int $index, string $readmeMd): ModificationContext
    {
        $this->readme[$index] = $readmeMd;

        return $this;
    }
}
