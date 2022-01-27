<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Filesystem\ProcessibleFile;

use Symfony\Component\Finder\SplFileInfo;

abstract class AbstractFile
{
    /**
     * @var SplFileInfo $fileInfo
     */
    private SplFileInfo $fileInfo;

    /**
     * @var string $code
     */
    private string $code;

    /**
     * @param SplFileInfo $fileInfo
     * @return void
     */
    public function init(SplFileInfo $fileInfo): void
    {
        $this->fileInfo = $fileInfo;
        $this->code = $fileInfo->getFilenameWithoutExtension();
    }

    /**
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Get SplFileInfo object. For internal use only. Any metadata must be provided via individual public methods
     *
     * @return SplFileInfo
     */
    protected function getFileInfo(): SplFileInfo
    {
        return $this->fileInfo;
    }

    /**
     * @param array $data
     * @return void
     * @throws \Exception
     */
    abstract protected function validate(array $data): void;
}
