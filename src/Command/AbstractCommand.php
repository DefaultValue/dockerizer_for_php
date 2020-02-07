<?php

declare(strict_types=1);

namespace App\Command;

use App\CommandQuestion\QuestionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractCommand extends \Symfony\Component\Console\Command\Command
{
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
     * @var \App\CommandQuestion\QuestionPool $questionPool
     */
    private $questionPool;

    /**
     * SetUpMagento constructor.
     * @param \App\Config\Env $env
     * @param \App\CommandQuestion\QuestionPool $questionPool
     * @param null $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\CommandQuestion\QuestionPool $questionPool,
        $name = null
    ) {
        $this->env = $env;
        $this->questionPool = $questionPool;
        parent::__construct($name);
    }

    /**
     * Get a list of questions to populate options/arguments and be able to ask for additional input if needed.
     *
     * @return array
     */
    abstract public function getQuestions(): array;

    /**
     * Get input parameters or ask to enter/choose them if needed
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $questionCode
     * @return mixed
     */
    protected function ask(InputInterface $input, OutputInterface $output, string $questionCode)
    {
        /** @var QuestionInterface $question */
        $question = $this->getQuestion($questionCode);
        return $question->ask($input, $output, $this->getHelper('question'));
    }

    /**
     * @param string $questionCode
     * @return \App\CommandQuestion\QuestionInterface
     */
    private function getQuestion(string $questionCode): QuestionInterface
    {
        return $this->questionPool->get($questionCode);
    }

    /**
     * Add options/argument to command when configuring it.
     * Commands should not be aware of these options/argument and thus have less configurations and code.
     * Questions are re-usable and reduce code duplication.
     */
    protected function configure(): void
    {
        /** @var string $question */
        foreach ($this->getQuestions() as $question) {
            $this->getQuestion($question)->addCommandParameters($this);
        }

        parent::configure();
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
