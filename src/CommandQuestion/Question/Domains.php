<?php

declare(strict_types=1);

namespace App\CommandQuestion\Question;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class Domains extends \App\CommandQuestion\AbstractQuestion
{
    public const QUESTION = 'domains_question';

    public const OPTION_DOMAINS = 'domains';

    /**
     * @var \App\Service\DomainValidator $domainValidator
     */
    private $domainValidator;

    /**
     * PhpVersion constructor.
     * @param \App\Service\DomainValidator $domainValidator
     */
    public function __construct(\App\Service\DomainValidator $domainValidator)
    {
        $this->domainValidator = $domainValidator;
    }

    /**
     * @inheritDoc
     */
    public function addCommandParameters(Command $command): void
    {
        $command->addOption(
            self::OPTION_DOMAINS,
            null,
            InputOption::VALUE_OPTIONAL,
            'Domains list (space-separated)'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param QuestionHelper $questionHelper
     * @return array
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper
    ): array {
        // 3. Production domains
        if (!$domains = $input->getOption(self::OPTION_DOMAINS)) {
            $question = new Question(
                'Enter space-separated list of domains (including non-www and www version if needed): '
            );
            $domains = $questionHelper->ask($input, $output, $question);

            if (!$domains) {
                throw new \InvalidArgumentException('Domains list is empty');
            }
        }

        if (!is_array($domains)) {
            $domains = explode(' ', $domains);
        }

        $domains = array_filter($domains);

        foreach ($domains as $domain) {
            if (!$this->domainValidator->isValid($domain)) {
                throw new \InvalidArgumentException("Production domain is not valid: $domain");
            }
        }

        return $domains;
    }
}
