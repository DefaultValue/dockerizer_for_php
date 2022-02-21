<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Filesystem\ProcessibleFile;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use Symfony\Component\Finder\Finder;

abstract class AbstractFileCollection implements \IteratorAggregate
{
    public const PROCESSIBLE_FILE_INSTANCE = '';

    /**
     * @var array $files
     */
    private array $files;

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

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->parseFiles());
    }

    /**
     * @return array
     */
    public function getCodes(): array
    {
        $this->parseFiles();

        return array_keys($this->files);
    }

    /**
     * @param string $code
     * @return Template|Service
     */
    public function getByCode(string $code): Template|Service
    {
        $this->parseFiles();

        if (!isset($this->files[$code])) {
            throw new \InvalidArgumentException("File with name `$code` (without extension) does not exist");
        }

        return $this->files[$code];
    }

    /**
     * @return Template[]|Service[]
     */
    private function parseFiles(): array
    {
        if (isset($this->files)) {
            return $this->files;
        }

        $dir = $this->dockerizerRootDir . $this->dirToScan;
        $this->files = [];

        foreach (Finder::create()->in($dir)->files()->name(['*.yaml', '*.yml']) as $fileInfo) {
            /** @var Template|Service $file */
            $file = $this->factory->get(static::PROCESSIBLE_FILE_INSTANCE);
            $file->init($fileInfo);
            $this->files[$file->getCode()] = $file;
        }

        ksort($this->files);

        return $this->files;
    }
}
