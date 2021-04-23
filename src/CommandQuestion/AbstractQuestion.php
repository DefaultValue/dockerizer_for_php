<?php

declare(strict_types=1);

namespace App\CommandQuestion;

abstract class AbstractQuestion implements QuestionInterface
{
    /**
     * Unique and non-empty question code
     */
    public const OPTION_NAME = '';

    /**
     * @return string
     */
    public function getOptionName(): string
    {
        if (!static::OPTION_NAME) {
            throw new \RuntimeException('Command question must have a code.');
        }

        return static::OPTION_NAME;
    }
}
