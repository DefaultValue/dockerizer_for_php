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
     * @param \App\CommandQuestion\Question\Domains $domains
     * @param \App\CommandQuestion\Question\PhpVersion $phpVersion
     * @param \App\CommandQuestion\Question\ComposerVersion $composerVersion
     * @param \App\CommandQuestion\Question\MysqlContainer $mysqlContainer
     * @param \App\CommandQuestion\Question\ProjectMountRoot $projectRoot
     * @param \App\CommandQuestion\Question\WebRoot $webRoot,
     */
    public function __construct(
        \App\CommandQuestion\Question\Domains          $domains,
        \App\CommandQuestion\Question\PhpVersion       $phpVersion,
        \App\CommandQuestion\Question\ComposerVersion  $composerVersion,
        \App\CommandQuestion\Question\MysqlContainer   $mysqlContainer,
        \App\CommandQuestion\Question\ProjectMountRoot $projectRoot,
        \App\CommandQuestion\Question\WebRoot          $webRoot
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
