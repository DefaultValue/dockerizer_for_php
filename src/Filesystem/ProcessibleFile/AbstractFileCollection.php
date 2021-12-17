<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Filesystem\ProcessibleFile;

use DefaultValue\Dockerizer\Docker\Compose\Composition\Service;
use DefaultValue\Dockerizer\Docker\Compose\Composition\Template;
use Symfony\Component\Finder\Finder;

abstract class AbstractFileCollection
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

    /**
     * @return Template[]
     */
    public function getProcessibleFiles(): array
    {
        if (!empty($this->files)) {
            return $this->files;
        }

        $dir = $this->dockerizerRootDir . $this->dirToScan;

        foreach (Finder::create()->files()->in($dir)->name(['*.yaml', '*.yml']) as $fileInfo) {
            $code = $fileInfo->getFilenameWithoutExtension();

            /** @var Template|Service $template */
            $processibleFile = $this->factory->get(static::PROCESSIBLE_FILE_INSTANCE);
            $processibleFile->setCode($code)
                ->setFileInfo($fileInfo);
            $this->files[$code] = $processibleFile;
        }

        ksort($this->files);

        return $this->files;
    }

    /**
     * @return array
     */
    public function getCodes(): array
    {
        if (empty($this->files)) {
            $this->getProcessibleFiles();
        }

        return array_keys($this->files);
    }

    /**
     * @param string $code
     * @return Template|Service
     */
    public function getProcessibleFile(string $code): Template|Service
    {
        if (empty($this->files)) {
            $this->getProcessibleFiles();
        }

        if (!isset($this->files[$code])) {
            throw new \InvalidArgumentException("File with name `$code` (without extension) does not exist");
        }

        return $this->files[$code];
    }
}
