<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Filesystem;

use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Filesystem\Exception\IOException;

class Filesystem extends \Symfony\Component\Filesystem\Filesystem
{
    /**
     * @param \DefaultValue\Dockerizer\Console\Shell\Env $env
     * @param string $dockerizerRootDir
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Console\Shell\Env $env,
        private string $dockerizerRootDir
    ) {
    }

    /**
     * Get path to the directory, create it if needed
     *
     * @param string $dir
     * @param bool $create
     * @param bool $canBeNotWriteable
     * @return string
     */
    public function getDirPath(string $dir, bool $create = true, bool $canBeNotWriteable = false): string
    {
        if (!$this->isAbsolutePath($dir)) {
            $dir = $this->env->getProjectsRootDir() .
                str_replace('/', DIRECTORY_SEPARATOR, trim($dir, DIRECTORY_SEPARATOR)) .
                DIRECTORY_SEPARATOR;
        }

        if (
            !str_starts_with($dir, $this->env->getProjectsRootDir())
            && !str_starts_with($dir, sys_get_temp_dir())
        ) {
            throw new \DomainException('Operating outside the PROJECTS_ROOT_DIR is not allowed!');
        }

        if ($create) {
            $this->mkdir($dir);
        }

        if (!is_dir($dir)) {
            throw new \RuntimeException('Not a directory: ' . $dir);
        }

        return $dir;
    }

    /**
     * @param string $dirPath
     * @return bool
     */
    public function isEmptyDir(string $dirPath): bool
    {
        $handle = opendir($dirPath);

        while (false !== ($entry = readdir($handle))) {
            if ($entry !== '.' && $entry !== '..') {
                closedir($handle);

                return false;
            }
        }

        closedir($handle);

        return true;
    }

    /**
     * @param string $path
     * @param bool $canBeLink
     * @return bool
     */
    public function isFile(string $path, bool $canBeLink = false): bool
    {
        $fileInfo = new \SplFileInfo($path);

        return $fileInfo->getType() === 'file' || ($canBeLink && $fileInfo->isFile());
    }

    /**
     * @param string $path
     * @return string
     */
    public function fileGetContents(string $path): string
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new FileNotFoundException(null, 0, null, $path);
        }

        if (!str_starts_with(realpath($path), $this->env->getProjectsRootDir())) {
            throw new \InvalidArgumentException("File $path is outside the allowed directories list!");
        }

        if (!($content = file_get_contents($path))) {
            throw new IOException("Can't read from file $path!");
        }

        return $content;
    }

    /**
     * @param string $path
     * @param string $content
     * @param int $flags
     * @return void
     */
    public function filePutContents(string $path, string $content, int $flags = 0): void
    {
        if (file_exists($path)) {
            $path = realpath($path);
        }

        if (!str_starts_with($path, $this->env->getProjectsRootDir())) {
            throw new \InvalidArgumentException("File $path is outside the allowed directories list!");
        }

        if (!file_put_contents($path, $content, $flags)) {
            throw new IOException("Can't write to file $path!");
        }
    }

    /**
     * Must move elsewhere in case new methods are added
     *
     * @return array
     * @throws \JsonException
     */
    public function getAuthJson(): array
    {
        $authJsonLocation = $this->dockerizerRootDir . 'config' . DIRECTORY_SEPARATOR . 'auth.json';

        if (!file_exists($authJsonLocation)) {
            throw new \RuntimeException(
                "Magento auth.json does not exist in $authJsonLocation! " .
                'Ensure that file exists and contains your Magento marketplace credentials.'
            );
        }

        return json_decode(file_get_contents($authJsonLocation), true, 512, JSON_THROW_ON_ERROR);
    }
}
