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
     * @var \App\Service\Shell $shell
     */
    protected $shell;

    /**
     * @var \App\CommandQuestion\QuestionPool $questionPool
     */
    private $questionPool;

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
     * @param \App\Service\Shell $shell
     * @param \App\CommandQuestion\QuestionPool $questionPool
     * @param null $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Shell $shell,
        \App\CommandQuestion\QuestionPool $questionPool,
        $name = null
    ) {
        $this->env = $env;
        $this->questionPool = $questionPool;
        $this->shell = $shell;
        parent::__construct($name);
    }

    /**
     * Get a list of questions to populate options/arguments and be able to ask for additional input if needed.
     *
     * @return array
     */
    abstract public function getQuestions(): array;

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
     * Get input parameters or ask to enter/choose them if needed
     *
     * @param string $questionCode
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return mixed
     */
    protected function ask(string $questionCode, InputInterface $input, OutputInterface $output)
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
}
