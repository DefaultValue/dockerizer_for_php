<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Contracts\Service\;

class Domains implements InteractiveCommandOptionDefinitionInterface
{
    public const OPTION_NAME = 'domains';

    public function getName(): string
    {
        return self::OPTION_NAME;
    }

    public function getShortcut(): string
    {
        return '';
    }

    public function getMode(): int
    {
        return InputOption::VALUE_REQUIRED;
    }

    public function getDescription(): string
    {
        return 'Domains list (space-separated)';
    }

    /**
     * @return void
     */
    public function getDefault(): mixed
    {
        return null;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param QuestionHelper $questionHelper
     * @param array ...$arguments
     * @return mixed
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        ...$arguments
    ): mixed {
        if (!$domains = $input->getOption(self::OPTION_NAME)) {
            $question = new Question(
                '<info>Enter space-separated list of domains (including non-www and www version if needed): </info>'
            );
            $domains = $questionHelper->ask($input, $output, $question);

            if (!$domains) {
                throw new \InvalidArgumentException('Domains list is empty');
            }
        }

        if (!is_array($domains)) {
            $domains = explode(' ', $domains);
        }

        $domains = array_filter($domains);

        $output->writeln('');

        return $domains;
    }

    public function validate($value): bool
    {
        throw new \Exception('Not implemented');
    }
}
