<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Filesystem\ProcessibleFile;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use DefaultValue\Dockerizer\Docker\Compose\Composition\DevTools;
use Symfony\Component\Finder\Finder;

abstract class AbstractFileCollection implements
    \IteratorAggregate,
    \DefaultValue\Dockerizer\Filesystem\ProjectRootAwareInterface
{
    public const PROCESSABLE_FILE_INSTANCE = '';

    /**
     * @var array $items
     */
    private array $items;

    /**
     * @param \DefaultValue\Dockerizer\DependencyInjection\Factory $factory
     * @param string $dockerizerRootDir
     * @param string $dirToScan
     */
    public function __construct(
        private \DefaultValue\Dockerizer\DependencyInjection\Factory $factory,
        private string $dockerizerRootDir,
        private string $dirToScan
    ) {
    }


    /**
     * @return Template[]|Service[]|DevTools[]
     */
    public function getItems(): array
    {
        if (isset($this->items)) {
            return $this->items;
        }

        $dir = $this->dockerizerRootDir . $this->dirToScan;
        $this->items = [];

        foreach (Finder::create()->in($dir)->files()->name(['*.yaml', '*.yml']) as $fileInfo) {
            /** @var Template|Service|DevTools $file */
            $file = $this->factory->get(static::PROCESSABLE_FILE_INSTANCE);
            $file->init($fileInfo);
            $this->items[$file->getCode()] = $file;
        }

        ksort($this->items);

        return $this->items;
    }

    /**+
     * @return \Traversable
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->getItems());
    }

    /**
     * @return string[]
     */
    public function getCodes(): array
    {
        return array_keys($this->getItems());
    }

    /**
     * @param string $code
     * @return Template|Service|DevTools
     */
    public function getByCode(string $code): Template|Service|DevTools
    {
        if (!isset($this->getItems()[$code])) {
            throw new \InvalidArgumentException("File with name `$code` (without extension) does not exist");
        }

        return $this->getItems()[$code];
    }
}
