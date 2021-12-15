<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

interface InteractiveOptionInterface
{
    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param QuestionHelper $questionHelper
     * @param array $arguments
     * @return mixed
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        ...$arguments
    ): mixed;
}
