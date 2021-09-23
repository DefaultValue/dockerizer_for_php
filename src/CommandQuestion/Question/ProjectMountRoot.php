<?php

declare(strict_types=1);

namespace App\CommandQuestion\Question;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Set custom project root in case it is not the current folder.
 * External folder with project files will be mounted to '/var/www/html'.
 */
class ProjectMountRoot extends \App\CommandQuestion\AbstractQuestion
{
    public const OPTION_NAME = 'mount-root';

    /**
     * @inheritDoc
     */
    public function addCommandParameters(Command $command): void
    {
        $command->addOption(
            self::OPTION_NAME,
            null,
            InputOption::VALUE_OPTIONAL,
            'Relative Project Root path (leave empty to mount current folder)'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param QuestionHelper $questionHelper
     * @return string
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper
    ): string {
        if (!$projectMountRoot = $input->getOption(self::OPTION_NAME)) {
            $question = new Question(
                "<info>Enter relative path to be mounted as <fg=blue>'/var/www/html'</fg=blue> (for example, <fg=blue>'../app/'</fg=blue>). Leave empty for current folder: </info>\n> ",
                '.'
            );
            $projectMountRoot = trim((string) $questionHelper->ask($input, $output, $question), DIRECTORY_SEPARATOR);

            if ($projectMountRoot && !is_dir($projectMountRoot)) {
                throw new \InvalidArgumentException("Project Root '$projectMountRoot' is not a valid directory path!");
            }

            $projectMountRoot = $projectMountRoot ?: '.';
            $output->writeln("<info>Project root is: <fg=blue>$projectMountRoot</fg=blue></info>");
            $output->writeln('');
        }

        return rtrim($projectMountRoot, DIRECTORY_SEPARATOR);
    }
}
