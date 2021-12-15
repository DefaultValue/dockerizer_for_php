<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Filesystem;

use Symfony\Component\Finder\SplFileInfo;

/**
 * Prepare info to save file in the future.
 * This object holds future file STATE and MUST NOT be used instead of \Symfony\Component\Filesystem\Filesystem!
 */
class File extends SplFileInfo
{
    private string $absolutePath;

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
//
//    /**
//     * @return string
//     */
//    public function getContent(): string
//    {
//        if (!isset($this->content)) {
//            $this->content = $this->read
//        }
//
//        return $this->content;
//    }

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
