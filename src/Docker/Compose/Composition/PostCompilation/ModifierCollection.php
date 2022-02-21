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
     * @param array $yamlContent
     * @return string
     */
    public function modify(array &$yamlContent): string
    {
        $readme = [];

        foreach ($this->postCompilationModifiers as $modifier) {
            $modifier->modify($yamlContent, $readme);
        }

        return implode("\n\n", $readme);
    }
}
