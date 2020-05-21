<?php

declare(strict_types=1);

namespace App\CommandQuestion;

abstract class AbstractQuestion implements QuestionInterface
{
    /**
     * Unique and non-empty question code
     */
    public const QUESTION = '';

    /**
     * @return string
     */
    public function getCode(): string
    {
        if (!static::QUESTION) {
            throw new \RuntimeException('Command question must have a code.');
        }

        return static::QUESTION;
    }
}
