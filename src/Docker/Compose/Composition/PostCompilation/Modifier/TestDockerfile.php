<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\Modifier;

use DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModificationContext;
use DefaultValue\Dockerizer\Platform\Magento\AppContainers;
use DefaultValue\Dockerizer\Shell\Shell;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

/**
 * Used by DV for testing purposes
 * Used with the `magento:test-dockerfiles` command to set a Dockerfile instead of using an image
 */
class TestDockerfile extends AbstractSslAwareModifier implements
    \DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModifierInterface
{
    // May be better to pass this is a command argument
    private const BUILD_ROOT = 'docker_infrastructure' . DIRECTORY_SEPARATOR;
    private const DOCKERFILES_ROOT = 'templates' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR;

    private bool $active = false;

    private string $dockerComposeDir;

    /**
     * @param \DefaultValue\Dockerizer\Docker\Compose\Composition $composition
     * @param \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     * @param \DefaultValue\Dockerizer\Shell\Shell $shell
     * @param \DefaultValue\Dockerizer\Docker\Image $dockerImage
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Docker\Compose\Composition $composition,
        private \DefaultValue\Dockerizer\Filesystem\Filesystem $filesystem,
        private \DefaultValue\Dockerizer\Shell\Env $env,
        private \DefaultValue\Dockerizer\Shell\Shell $shell,
        private \DefaultValue\Dockerizer\Docker\Image $dockerImage
    ) {
    }

    /**
     * @param bool $active
     * @return void
     */
    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    /**
     * @inheritDoc
     */
    public function modify(ModificationContext $modificationContext): void
    {
        if (!$this->active) {
            return;
        }

        $modificationContext->setCompositionYaml($this->processDockerComposeYaml(
            $modificationContext->getCompositionYaml(),
            'production'
        ));

        $modificationContext->setDevToolsYaml($this->processDockerComposeYaml(
            $modificationContext->getDevToolsYaml(),
            'development'
        ));

        $this->dockerComposeDir = $modificationContext->getDockerComposeDir();
    }

    /**
     * @return string
     */
    public function getDockerComposeDir(): string
    {
        return $this->dockerComposeDir;
    }

    /**
     * @param array $dockerComposeYaml
     * @param string $dockerfileNamePrefix
     * @return array
     */
    private function processDockerComposeYaml(array $dockerComposeYaml, string $dockerfileNamePrefix): array
    {
        $phpVersion = $this->composition->getParameterValue('php_version');
        $buildRoot = $this->env->getProjectsRootDir() . self::BUILD_ROOT;
        $dockerfilesDirectory = $buildRoot . self::DOCKERFILES_ROOT . $phpVersion . DIRECTORY_SEPARATOR;
        $dockerfilePath = $dockerfilesDirectory . $dockerfileNamePrefix . '.Dockerfile';

        if (!$this->filesystem->isFile($dockerfilePath)) {
            throw new FileNotFoundException(null, 0, null, $dockerfilePath);
        }

        $fromImage = $this->shell->mustRun("cat $dockerfilePath | grep --ignore-case '^FROM'")->getOutput();
        $fromImage = trim(str_ireplace('from ', '', $fromImage));
        // Pull the latest image version if it exists. Must be optional because the image may not be in the registry yet
        $this->dockerImage->pull($fromImage, false, false);

        unset($dockerComposeYaml['services'][AppContainers::PHP_SERVICE]['image']);
        $dockerComposeYaml['services'][AppContainers::PHP_SERVICE]['build'] = [
            'context' => $buildRoot,
            'dockerfile' => $dockerfilePath
        ];

        return $dockerComposeYaml;
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 999;
    }
}
