<?php
declare(strict_types=1);

namespace App\CommandQuestion\Question;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class WebRoot extends \App\CommandQuestion\AbstractQuestion
{
    public const OPTION_NAME = 'web-root';

    public function addCommandParameters(Command $command): void
    {
        $command->addOption(
            self::OPTION_NAME,
            null,
            InputOption::VALUE_OPTIONAL,
            'Web Root'
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
        if (!$webRoot = $input->getOption(self::OPTION_NAME)) {
            $question = new Question(<<<'EOF'
                <info>Enter web root that is in the mounted directory. Default web root is <fg=blue>pub/</fg=blue>
                Leave empty to use default, enter new web root or enter <fg=blue>/</fg=blue> for current folder: </info>
                EOF);

            $webRoot = trim((string) $questionHelper->ask($input, $output, $question));

            if (!$webRoot) {
                $webRoot = 'pub/';
            } else {
                $webRoot = trim($webRoot, '/') . '/';
            }
        }

        return $webRoot;
    }
}
