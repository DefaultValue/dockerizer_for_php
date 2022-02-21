<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\Modifier;

/**
 * If there are containers with the label `traefik.enable=true` -
 */
class Traefik implements \DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModifierInterface
{
    /**
     * @inheritDoc
     */
    public function modify(array &$yamlContent, array &$readme, string $projectRoot, string $dockerComposeDir): void
    {

    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 200;
    }
}
