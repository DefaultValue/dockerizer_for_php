<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Filesystem;

use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Filesystem\Exception\IOException;

class Filesystem extends \Symfony\Component\Filesystem\Filesystem
{
    /**
     * @param \DefaultValue\Dockerizer\Shell\Env $env
     * @param string $dockerizerRootDir
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Shell\Env $env,
        private string $dockerizerRootDir
    ) {
    }

    /**
     * Get path to the directory, create it if needed
     *
     * @param string|iterable $dirs
     * @param int $mode
     * @return void
     */
    public function mkdir(string|iterable $dirs, int $mode = 0777): void
    {
        $this->firewall($dirs);

        parent::mkdir($dirs, $mode);
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
        return $this->isA('file', $path, $canBeLink);
    }

    /**
     * @param string $path
     * @param bool $canBeLink
     * @return bool
     */
    public function isDir(string $path, bool $canBeLink = false): bool
    {
        return $this->isA('dir', $path, $canBeLink);
    }

    /**
     * @param string $filesystemObjectType
     * @param string $path
     * @param bool $canBeLink
     * @return bool
     */
    private function isA(string $filesystemObjectType, string $path, bool $canBeLink): bool
    {
        if (!$this->exists($path)) {
            return false;
        }

        $fileInfo = new \SplFileInfo($path);

        return $fileInfo->getType() === $filesystemObjectType || ($canBeLink && $fileInfo->isFile());
    }

    /**
     * @param string $path
     * @return string
     */
    public function fileGetContents(string $path): string
    {
        $this->firewall($path);

        if (!$this->exists($path) || !is_readable($path)) {
            throw new FileNotFoundException(null, 0, null, $path);
        }

        $content = file_get_contents($path);

        if ($content === false) {
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
        $this->firewall($path);

        $dir = \dirname($path);

        if (!$this->exists($dir)) {
            $this->mkdir($dir);
        }

        if (!file_put_contents($path, $content, $flags)) {
            throw new IOException("Can't write to file $path!");
        }
    }

    /**
     * Removes files or directories.
     *
     * @throws IOException When removal fails
     */
    public function remove(string|iterable $files): void
    {
        $this->firewall($files);

        parent::remove($files);
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

        if (!$this->exists($authJsonLocation)) {
            throw new \RuntimeException(
                "Magento auth.json does not exist in $authJsonLocation! " .
                'Ensure that file exists and contains your Magento marketplace credentials.'
            );
        }

        return json_decode($this->fileGetContents($authJsonLocation), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string|iterable $paths
     * @return void
     */
    public function firewall(string|iterable $paths): void
    {
        if ($paths instanceof \Traversable) {
            $paths = iterator_to_array($paths, false);
        } elseif (!\is_array($paths)) {
            $paths = [$paths];
        }

        $systemTempDir = sys_get_temp_dir();

        foreach ($paths as $path) {
            if ($this->exists($path)) {
                $path = realpath($path);
            }

            if (
                !str_starts_with($path, $systemTempDir)
                && !str_starts_with($path, $this->env->getProjectsRootDir())
            ) {
                throw new \InvalidArgumentException(
                    "File or directory $path is outside the system temp dir and PROJECTS_ROOT_DIR!"
                );
            }
        }
    }
}
