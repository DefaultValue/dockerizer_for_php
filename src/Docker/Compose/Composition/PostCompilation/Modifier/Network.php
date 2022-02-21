<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\Modifier;

class Network implements \DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModifierInterface
{
    /**
     * @inheritDoc
     */
    public function modify(array &$yamlContent, array &$readme): void
    {
        // TODO: Implement modify() method.
    }
}
