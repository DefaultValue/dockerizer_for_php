<?php

declare(strict_types=1);

namespace App\CommandQuestion\Question;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

class PhpVersion extends \App\CommandQuestion\AbstractQuestion
{
    /**
     * PHP version based on the available templates from the repo: https://github.com/DefaultValue/docker_infrastructure
     */
    public const OPTION_NAME = 'php';

    /**
     * @var \App\Service\Filesystem $filesystem
     */
    private $filesystem;

    /**
     * PhpVersion constructor.
     * @param \App\Service\Filesystem $filesystem
     */
    public function __construct(\App\Service\Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @inheritDoc
     */
    public function addCommandParameters(Command $command): void
    {
        $command->addOption(
            self::OPTION_NAME,
            null,
            InputOption::VALUE_OPTIONAL,
            'PHP version: from 5.6 to 7.4'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param QuestionHelper $questionHelper
     * @param array $allowedPhpVersions
     * @return string
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        array $allowedPhpVersions = []
    ): string {
        $availablePhpVersions = $this->filesystem->getAvailablePhpVersions();
        $phpVersion = $input->getOption(self::OPTION_NAME)
            ? number_format((float) $input->getOption(self::OPTION_NAME), 1)
            : false;

        if ($phpVersion && !in_array($phpVersion, $availablePhpVersions, true)) {
            $output->writeln('<error>Provided PHP version is not available!</error>');
            $phpVersion = false;
        }

        if (!$phpVersion) {
            if (!empty($allowedPhpVersions)) {
                $availablePhpVersions = array_intersect($allowedPhpVersions, $availablePhpVersions);
            }

            if (empty($availablePhpVersions)) {
                throw new \RuntimeException(
                    'Can not find a suitable PHP version! ' .
                    'Please, contact the repository maintainer ASAP (see composer.json for authors)'
                );
            }

            usort($availablePhpVersions, 'version_compare');
            $highestPhpVersion = $availablePhpVersions[count($availablePhpVersions) - 1];

            $question = new ChoiceQuestion(
                "<info>Select PHP version. Press Enter to use <fg=blue>$highestPhpVersion</fg=blue></info>",
                $availablePhpVersions,
                $highestPhpVersion
            );
            $question->setErrorMessage('PHP version %s is invalid');

            // Question is not asked in the no-interaction mode
            if (!$phpVersion = $questionHelper->ask($input, $output, $question)) {
                $phpVersion = array_pop($availablePhpVersions);
            }

            $output->writeln(
                "<info>Using the following PHP version: </info><fg=blue>$phpVersion</fg=blue>\n"
            );
        }

        return $phpVersion;
    }
}
