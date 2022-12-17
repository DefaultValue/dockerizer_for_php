<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\PostCompilation;

class ModifierCollection
{
    /**
     * @param ModifierInterface[] $postCompilationModifiers
     */
    public function __construct(
        private iterable $postCompilationModifiers
    ) {
    }

    /**
     * Modify YAML of the main composition file if needed, and generate Readme.md file if needed.
     * For now, other files than the main file are not processed. Dev tools file is not processed as well.
     *
     * @param ModificationContext $modificationContext
     */
    public function modify(ModificationContext $modificationContext): void
    {
        $sortedModifiers = [];

        foreach ($this->postCompilationModifiers as $modifier) {
            if (!$modifier instanceof ModifierInterface) {
                throw new \RuntimeException(sprintf(
                    'Composition modifier of class %s must implement ModifierInterface',
                    get_class($modifier)
                ));
            }

            if (isset($sortedModifiers[$modifier->getSortOrder()])) {
                throw new \RuntimeException('Two modifiers have thew same sort order!');
            }

            $sortedModifiers[$modifier->getSortOrder()] = $modifier;
        }

        /** @var ModifierInterface $modifier */
        foreach ($sortedModifiers as $modifier) {
            $modifier->modify($modificationContext);
        }
    }
}
