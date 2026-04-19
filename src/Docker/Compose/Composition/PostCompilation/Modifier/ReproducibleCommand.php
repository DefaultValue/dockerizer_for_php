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
use DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation\ModifierInterface;

/**
 * Append the shell command that rebuilds this composition to the Readme.
 * The command is produced by `composition:build-from-template` with secrets already redacted,
 * so the generated `.dockerizer/` directory can be committed to a repository without leaking
 * credentials. Runs last (sort order 999) so it appears as the final Readme section.
 */
class ReproducibleCommand implements ModifierInterface
{
    /**
     * @inheritDoc
     */
    public function modify(ModificationContext $modificationContext): void
    {
        $command = $modificationContext->getReproducibleCommand();

        if ($command === null || $command === '') {
            return;
        }

        $readmeMd = "## Rebuild this composition ##\n\n"
            . "Use this command to regenerate the composition. "
            . "Edit the options first if you want to recreate it with different settings. "
            . "Secrets (passwords, usernames, tokens) are shown as `[redacted]` — "
            . "replace them with real values before running.\n\n"
            . "```shell\n"
            . $command . "\n"
            . "```";

        $modificationContext->appendReadme($this->getSortOrder(), $readmeMd);
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 999;
    }
}
