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
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @param string $code
     * @return $this
     */
    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    /**
     * @return SplFileInfo
     */
    public function getFileInfo(): SplFileInfo
    {
        return $this->fileInfo;
    }

    /**
     * @param SplFileInfo $fileInfo
     * @return $this
     * @throws \Exception
     */
    public function setFileInfo(SplFileInfo $fileInfo): self
    {
        if (isset($this->fileInfo)) {
            // This is not a value object, which is better for testing during active development
            throw new \RuntimeException('Attempt to change the stateful service');
        }

        $this->fileInfo = $fileInfo;
        $this->validate();

        return $this;
    }

    /**
     * @return void
     * @throws \Exception
     */
    abstract protected function validate(): void;
}
