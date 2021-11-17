<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Filesystem;

/**
 * Prepare info to save file in the future.
 * This object holds future file STATE and MUST NOT be used instead of \Symfony\Component\Filesystem\Filesystem!
 */
class NewFile extends \Symfony\Component\Filesystem\Filesystem
{
    private string $absolutePath;

    private string $content;

    /**
     * @return string
     */
    public function getAbsolutePath(): string
    {
        return $this->absolutePath;
    }

    /**
     * @param string $absolutePath
     * @return $this
     */
    public function setAbsolutePath(string $absolutePath): self
    {
        $this->absolutePath = $absolutePath;

        return $this;
    }

    /**
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @param string $content
     * @return $this
     */
    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }
}
