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
     * @inheritDoc
     */
    public const QUESTION = 'php_version_question';

    /**
     * PHP version based on the available templates from the repo: https://github.com/DefaultValue/docker_infrastructure
     */
    private const OPTION_PHP_VERSION = 'php';
    /**
     * @var \App\Service\Filesystem
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
            self::OPTION_PHP_VERSION,
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
     * @param bool $noInteraction
     * @return string
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        array $allowedPhpVersions = [],
        bool $noInteraction = false
    ): string {
        // @TODO: move this to Filesystem
        $availablePhpVersions = array_filter(glob(
            $this->env->getDir('docker_infrastructure/templates/php/*')
        ), 'is_dir');

        $templatesDir = $this->env->getDir('docker_infrastructure/templates/php/');

        array_walk($availablePhpVersions, static function (&$value) use ($templatesDir) {
            $value = str_replace($templatesDir, '', $value);
        });

        $phpVersion = $input->getOption(self::OPTION_PHP_VERSION);

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
                    'Please, contact the repository maintainer ASAP (see composer.json for authors)!'
                );
            }

            if ($noInteraction) {
                $phpVersion = array_pop($availablePhpVersions);
            } else {
                $question = new ChoiceQuestion(
                    '<info>Select PHP version:</info>',
                    $availablePhpVersions
                );
                $question->setErrorMessage('PHP version %s is invalid');

                $phpVersion = $questionHelper->ask($input, $output, $question);
            }

            $output->writeln(
                "<info>You have selected the following PHP version: </info><fg=blue>$phpVersion</fg=blue>"
            );
        }

        return (string) $phpVersion;
    }
}
