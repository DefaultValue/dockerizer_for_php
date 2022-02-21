<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker;

use Symfony\Component\Finder\Finder;

class Compose
{
    private const DOCKER_COMPOSE_NAME_PATTERNS = [
        'docker-compose*.yml',
        'docker-compose*.yaml'
    ];

    /**
     * @param \DefaultValue\Dockerizer\Console\Shell\Shell $shell
     * @param string $cwd
     */
    public function __construct(
        private \DefaultValue\Dockerizer\Console\Shell\Shell $shell,
        private string $cwd = ''
    ) {
    }

    /**
     * Set current working directory for the class instance
     *
     * @param string $cwd
     * @return Compose
     */
    public function setCwd(string $cwd): Compose
    {
        if (!$cwd) {
            throw new \InvalidArgumentException('Working directory must not be empty!');
        }

        return new self($this->shell, $cwd);
    }

    public function up()
    {
        throw new \Exception('To be implemented');
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function down(/* bool $volumes = true, bool $removeOrphans = true */): void
    {
        foreach ($this->locateDockerComposeFiles() as $dockerComposeFile) {
            if ($error = $this->shell->exec(
                ['docker-compose', '-f', $dockerComposeFile, 'down', '--remove-orphans'],
                $this->cwd
            )) {
                // @TODO: get back to this when compositions are fully configured (including networks)
                // throw new \RuntimeException($error);
                echo "@TODO: improve shutting down containers\n";
                echo "$error\n";
            }
        }
    }

    /**
     * @return Finder
     */
    private function locateDockerComposeFiles(): Finder
    {
        return Finder::create()->in($this->cwd)->files()->name(self::DOCKER_COMPOSE_NAME_PATTERNS);
    }
}
