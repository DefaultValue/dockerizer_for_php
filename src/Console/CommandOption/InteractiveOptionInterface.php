<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption;

use Symfony\Component\Console\Question\Question;

interface InteractiveOptionInterface
{
    /**
     * @return Question
     */
    public function getQuestion(): Question;
}
