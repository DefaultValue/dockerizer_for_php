<?php

declare(strict_types=1);

namespace App\CommandQuestion;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

interface QuestionInterface
{
    /**
     * Get question code
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Add options/argument to command when configuring it.
     * Commands should not be aware of these options/argument and thus have less configurations and code.
     * Questions are re-usable and reduce code duplication.
     *
     * @param Command $command
     * @return void
     */
    public function addCommandParameters(Command $command): void;

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param QuestionHelper $questionHelper
     * @return mixed
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper
    );
}
