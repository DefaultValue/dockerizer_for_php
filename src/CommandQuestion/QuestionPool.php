<?php

declare(strict_types=1);

namespace App\CommandQuestion;

class QuestionPool
{
    /**
     * @var QuestionInterface[] $questions
     */
    private $questions = [];

    /**
     * QuestionPool constructor.
     * @param Question\Domains $domains
     * @param Question\PhpVersion $phpVersion
     * @param Question\ComposerVersion $composerVersion
     * @param Question\MysqlContainer $mysqlContainer
     */
    public function __construct(
        \App\CommandQuestion\Question\Domains $domains,
        \App\CommandQuestion\Question\PhpVersion $phpVersion,
        \App\CommandQuestion\Question\ComposerVersion $composerVersion,
        \App\CommandQuestion\Question\MysqlContainer $mysqlContainer
    ) {
        /** @var QuestionInterface $question */
        foreach (func_get_args() as $question) {
            if (!$question instanceof QuestionInterface) {
                throw new \InvalidArgumentException('Question must implement ' . QuestionInterface::class);
            }

            $questionCode = $question->getOptionName();

            if (isset($this->questions[$questionCode])) {
                throw new \RuntimeException('Question code is not unique: ' . $questionCode);
            }

            $this->questions[$questionCode] = $question;
        }
    }

    /**
     * @param string $questionCode
     * @return QuestionInterface
     */
    public function get(string $questionCode): QuestionInterface
    {
        return $this->questions[$questionCode];
    }
}
