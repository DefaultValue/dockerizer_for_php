<?php

declare(strict_types=1);

namespace App\CommandQuestion;

use App\Command\Dockerize;
use App\Config\Env;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

class Domains
{
    /**
     * @var \App\Service\DomainValidator
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
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param QuestionHelper $questionHelper
     * @param array $domains
     * @return mixed
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        $domains = []
    ) {
        if ($domains === null) {
            $question = new Question('Enter space-separated list of development domains: ');

            if ($developmentDomainsAnswer = $this->getHelper('question')->ask($input, $output, $question)) {
                $developmentDomains = array_filter(explode(' ', $developmentDomainsAnswer));

                foreach ($developmentDomains as $domain) {
                    if (!$this->domainValidator->isValid($domain)) {
                        throw new \InvalidArgumentException("Development domain is not valid: $domain");
                    }
                }
            } else {
                $output->writeln('<into>Development domains are not set. Proceeding without them...</into>');
                $developmentDomains = [];
            }
        } elseif (empty($developmentDomains)) {
            $developmentDomains = [];
        } elseif (is_string($developmentDomains)) {
            $developmentDomains = array_filter(explode(' ', $developmentDomains));

            foreach ($developmentDomains as $domain) {
                if (!$this->domainValidator->isValid($domain)) {
                    throw new \InvalidArgumentException("Development domain is not valid: $domain");
                }
            }
        }
    }
}
