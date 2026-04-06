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

/**
 * Replaces the `.:/var/www/html` bind mount with a named volume for test commands on macOS.
 * This eliminates Docker Desktop VirtioFS overhead during heavy I/O operations like Magento installation,
 * reindexing, and fixture generation.
 *
 * The named volume lives inside Docker's VM and is not synced to the host filesystem.
 * It persists across container restarts (e.g., switchToDevTools) and is only removed during cleanup.
 */
class TestNamedVolume implements
    \DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModifierInterface
{
    public const VOLUME_NAME = 'app_data';

    private bool $active = false;

    /**
     * @param bool $active
     * @return void
     */
    public function setActive(bool $active): void
    {
        $this->active = $active && PHP_OS_FAMILY === 'Darwin';
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @inheritDoc
     */
    public function modify(ModificationContext $modificationContext): void
    {
        if (!$this->active) {
            return;
        }

        $modificationContext->setCompositionYaml(
            $this->replaceBindMount($modificationContext->getCompositionYaml())
        );

        if ($modificationContext->getDevToolsYaml()) {
            $modificationContext->setDevToolsYaml(
                $this->replaceBindMount($modificationContext->getDevToolsYaml())
            );
        }
    }

    /**
     * Replace `.:/var/www/html` bind mount with a named volume in all services
     *
     * @param array $yaml
     * @return array
     */
    private function replaceBindMount(array $yaml): array
    {
        if (!isset($yaml['services'])) {
            return $yaml;
        }

        foreach ($yaml['services'] as $serviceName => &$serviceConfig) {
            if (!isset($serviceConfig['volumes'])) {
                continue;
            }

            foreach ($serviceConfig['volumes'] as $index => $volume) {
                if (is_string($volume) && str_starts_with($volume, '.:/var/www/html')) {
                    $serviceConfig['volumes'][$index] = self::VOLUME_NAME . ':/var/www/html';
                    break;
                }
            }
        }

        unset($serviceConfig);

        // Add named volume definition
        $yaml['volumes'][self::VOLUME_NAME] = ['external' => false];

        return $yaml;
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 998;
    }
}
