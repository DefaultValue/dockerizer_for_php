<?php

declare(strict_types=1);

namespace App\Command;

abstract class AbstractCommand extends \Symfony\Component\Console\Command\Command
{
    public const OPTION_FORCE = 'force';

    /**
     * @var \App\Config\Env $env
     */
    protected $env;

    /**
     * @var \App\Service\Database
     */
    protected $database;

    /**
     * @var \App\Service\DomainValidator
     */
    protected $domainValidator;

    /**
     * @var string $domain
     */
    private $domain = '';

    /**
     * @var string $projectRoot
     */
    private $projectRoot = '';

    /**
     * SetUpMagento constructor.
     * @param \App\Config\Env $env
     * @param null $name
     */
    public function __construct(
        \App\Config\Env $env,
        $name = null
    ) {
        parent::__construct($name);
        $this->env = $env;
    }

    /**
     * @return string
     */
    protected function getDomain(): string
    {
        return $this->domain;
    }

    /**
     * @param string $domain
     */
    protected function setDomain(string $domain): void
    {
        $this->domain = $domain;
    }

    /**
     * @return string
     */
    protected function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * @param string $projectRoot
     */
    protected function setProjectRoot(string $projectRoot): void
    {
        $this->projectRoot = $projectRoot;
    }

    /**
     * @param string $destination
     */
    protected function copyAuthJson(string $destination = './'): void
    {
        if (!file_exists($destination . '/auth.json')) {
            $authJson = $this->env->getAuthJsonLocation();
            copy($authJson, $destination . '/auth.json');
        }
    }

    /**
     * @param string $command
     * @param bool $ignoreErrors
     * @return $this
     */
    protected function passthru(string $command, bool $ignoreErrors = false): self
    {
        $exitCode = 0;

        passthru($command, $exitCode);

        if ($exitCode && !$ignoreErrors) {
            throw new \RuntimeException('Execution failed. External command returned non-zero exit code.');
        }

        return $this;
    }

    /**
     * Execute commands with sudo. Only ONE BY ONE!
     * @param string $command
     * @param bool $ignoreErrors
     */
    protected function sudoPassthru(string $command, bool $ignoreErrors = false): void
    {

        $this->passthru("echo {$this->env->getUserRootPassword()} | sudo -S $command", $ignoreErrors);
    }

    /**
     * @param string $command
     * @return $this
     * @throws \InvalidArgumentException|\RuntimeException
     */
    protected function dockerExec(string $command): self
    {
        if (!$this->getDomain()) {
            throw new \InvalidArgumentException('Domain is not set. It must be set and equal to the container name.');
        }

        if (!shell_exec("docker ps | grep {$this->getDomain()} | grep 'Up '")) {
            throw new \RuntimeException("Can't continue because the container {$this->getDomain()} is not up and running.");
        }

        $command = "docker exec -it {$this->getDomain()} " . str_replace(["\r", "\n"], '', $command);

        echo "$command\n\n";
        $this->passthru($command);

        return $this;
    }
}
