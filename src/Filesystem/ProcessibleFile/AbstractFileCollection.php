<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Filesystem\ProcessibleFile;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use Symfony\Component\Finder\Finder;

abstract class AbstractFileCollection implements
    \IteratorAggregate,
    \DefaultValue\Dockerizer\Filesystem\ProjectRootAwareInterface
{
    public const PROCESSIBLE_FILE_INSTANCE = '';

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
     * @return Template[]|Service[]
     */
    public function getItems(): array
    {
        $this->parse();

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
     * @return array
     */
    public function getCodes(): array
    {
        return array_keys($this->getItems());
    }

    /**
     * @param string $code
     * @return Template|Service
     */
    public function getByCode(string $code): Template|Service
    {
        if (!isset($this->getItems()[$code])) {
            throw new \InvalidArgumentException("File with name `$code` (without extension) does not exist");
        }

        return $this->getItems()[$code];
    }

    /**
     * @return Template[]|Service[]
     */
    private function parse(): array
    {
        if (isset($this->items)) {
            return $this->items;
        }

        $dir = $this->dockerizerRootDir . $this->dirToScan;
        $this->items = [];

        foreach (Finder::create()->in($dir)->files()->name(['*.yaml', '*.yml']) as $fileInfo) {
            /** @var Template|Service $file */
            $file = $this->factory->get(static::PROCESSIBLE_FILE_INSTANCE);
            $file->init($fileInfo);
            $this->items[$file->getCode()] = $file;
        }

        ksort($this->items);

        return $this->items;
    }
}
