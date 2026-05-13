<?php

/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Question;

use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * ChoiceQuestion that carries an extra message rendered between the list of choices and the input prompt.
 * Long lists push the question text off-screen, so hints/recommendations belong directly above the prompt.
 */
class ChoiceQuestionWithRecommendation extends ChoiceQuestion
{
    private string $postChoicesMessage = '';

    public function getPostChoicesMessage(): string
    {
        return $this->postChoicesMessage;
    }

    public function setPostChoicesMessage(string $message): self
    {
        $this->postChoicesMessage = $message;

        return $this;
    }
}
