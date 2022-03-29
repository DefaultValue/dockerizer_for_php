<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Filesystem;

class Filesystem extends \Symfony\Component\Filesystem\Filesystem
{
    public function __construct(
        private \DefaultValue\Dockerizer\Console\Shell\Env $env,
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
}
