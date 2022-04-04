<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Filesystem;

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
