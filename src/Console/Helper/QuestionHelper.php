<?php

/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Helper;

use DefaultValue\Dockerizer\Console\Question\ChoiceQuestionWithRecommendation;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\SymfonyQuestionHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Extends Symfony's question helper to render a post-choices message between the choices list and the ` > ` prompt.
 * Only activates for {@see ChoiceQuestionWithRecommendation}; every other question falls through to the parent.
 */
class QuestionHelper extends SymfonyQuestionHelper
{
    protected function writePrompt(OutputInterface $output, Question $question): void
    {
        if (!$question instanceof ChoiceQuestionWithRecommendation || $question->getPostChoicesMessage() === '') {
            parent::writePrompt($output, $question);

            return;
        }

        $text = OutputFormatter::escapeTrailingBackslash($question->getQuestion());
        $default = $question->getDefault();
        $choices = $question->getChoices();

        if ($default === null) {
            $header = sprintf(' <info>%s</info>:', $text);
        } else {
            $defaultString = (string) $default;

            if ($question->isMultiselect()) {
                $labels = [];

                foreach (explode(',', $defaultString) as $value) {
                    $key = trim($value);
                    $labels[] = (string) ($choices[$key] ?? $key);
                }

                $header = sprintf(
                    ' <info>%s</info> [<comment>%s</comment>]:',
                    $text,
                    OutputFormatter::escape(implode(', ', $labels))
                );
            } else {
                $label = (string) ($choices[$defaultString] ?? $defaultString);
                $header = sprintf(
                    ' <info>%s</info> [<comment>%s</comment>]:',
                    $text,
                    OutputFormatter::escape($label)
                );
            }
        }

        $output->writeln($header);
        $output->writeln($this->formatChoiceQuestionChoices($question, 'comment'));
        $output->writeln($question->getPostChoicesMessage());
        $output->write($question->getPrompt());
    }
}
