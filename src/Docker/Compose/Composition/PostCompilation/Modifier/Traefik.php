<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\Modifier;

use DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModificationContext;

/**
 * Currently Traefik is the only supported reverse proxy
 * Traefik configuration is already defined inside the composition
 * It is possible to create services that will work with other proxies
 */
class Traefik implements \DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModifierInterface
{
    /**
     * @inheritDoc
     */
    public function modify(ModificationContext $modificationContext): void
    {
        // @TODO: add certificates to Treafik!
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 200;
    }
}
