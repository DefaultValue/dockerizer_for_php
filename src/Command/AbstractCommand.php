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
     * SetUpMagento constructor.
     * @param \App\Config\Env $env
     * @param \App\Service\Shell $shell
     * @param \App\CommandQuestion\QuestionPool $questionPool
     * @param ?string $name
     */
    public function __construct(
        \App\Config\Env $env,
        \App\Service\Shell $shell,
        \App\CommandQuestion\QuestionPool $questionPool,
        ?string $name = null
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
    abstract protected function getQuestions(): array;

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
     * Get input parameters or ask to enter/choose them if needed.
     * Proxy additional question parameters directly to the question.
     *
     * @param string $questionCode
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param array $additionalParams
     * @return mixed
     */
    protected function ask(string $questionCode, InputInterface $input, OutputInterface $output, ...$additionalParams)
    {
        /** @var QuestionInterface $question */
        $question = $this->getQuestion($questionCode);
        // @TODO: find proper pattern for this or the way to have an interface with variadic arguments
        return $question->ask($input, $output, $this->getHelper('question'), ...$additionalParams);
    }

    /**
     * @param string $questionCode
     * @return \App\CommandQuestion\QuestionInterface
     */
    private function getQuestion(string $questionCode): QuestionInterface
    {
        return $this->questionPool->get($questionCode);
    }
}
