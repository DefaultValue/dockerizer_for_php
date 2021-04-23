<?php

declare(strict_types=1);

namespace App\CommandQuestion\Question;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

class ComposerVersion extends \App\CommandQuestion\AbstractQuestion
{
    public const OPTION_NAME = 'composer-version';

    /**
     * @inheritDoc
     */
    public function addCommandParameters(Command $command): void
    {
        $command->addOption(
            self::OPTION_NAME,
            null,
            InputOption::VALUE_OPTIONAL,
            'Composer version (2 by default)',
            2
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param QuestionHelper $questionHelper
     * @return array
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper
    ): int {
        $composerVersion = (int) $input->getOption(self::OPTION_NAME);

        if ($composerVersion === 1 || $composerVersion === 2) {
            return $composerVersion;
        }

        $defaultComposerVersion = 2;

        $question = new ChoiceQuestion(
            "<info>Select Composer version. Press Enter to use <fg=blue>$defaultComposerVersion</fg=blue></info>",
            [
                1 => 1,
                2 => 2
            ],
            $defaultComposerVersion
        );
        $question->setErrorMessage('Composer version %s is invalid');

        // Question is not asked in the no-interaction mode
        if (!$composerVersion = $questionHelper->ask($input, $output, $question)) {
            $composerVersion = $defaultComposerVersion;
        }

        return (int) $composerVersion;
    }
}
