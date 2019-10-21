<?php
declare(strict_types=1);

namespace App\CommandQuestion;

use App\Command\Dockerize;
use App\Config\Env;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

class PhpVersion
{
    /**
     * @var Env
     */
    private $env;

    /**
     * PhpVersion constructor.
     * @param Env $env
     */
    public function __construct(Env $env)
    {
        $this->env = $env;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param QuestionHelper $questionHelper
     * @param array $allowedPhpVersions
     * @param bool $noInteraction
     * @return mixed
     */
    public function ask(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        array $allowedPhpVersions = [],
        bool $noInteraction = false
    ) {
        $availablePhpVersions = array_filter(glob(
            $this->env->getDir('docker_infrastructure/templates/php/*')
        ), 'is_dir');

        $templatesDir = $this->env->getDir('docker_infrastructure/templates/php/');

        array_walk($availablePhpVersions, static function (&$value) use ($templatesDir) {
            $value = str_replace($templatesDir, '', $value);
        });

        $phpVersion = $input->getOption(Dockerize::OPTION_PHP_VERSION);

        if ($phpVersion && !in_array($phpVersion, $availablePhpVersions, true)) {
            $output->writeln('<error>Provided PHP version is not available!</error>');
            $phpVersion = false;
        }

        if (!$phpVersion) {
            if (!empty($allowedPhpVersions)) {
                $availablePhpVersions = array_intersect($allowedPhpVersions, $availablePhpVersions);
            }

            if (empty($availablePhpVersions)) {
                throw new \RuntimeException('Can not find a suitable PHP version! Please, contact the repository maintainer ASAP (see composer.json for authors)!');
            }

            if ($noInteraction) {
                $phpVersion = array_pop($availablePhpVersions);
            } else {
                $question = new ChoiceQuestion(
                    '<info>Select PHP version:</info>',
                    $availablePhpVersions
                );
                $question->setErrorMessage('PHP version %s is invalid');

                $phpVersion = $questionHelper->ask($input, $output, $question);
            }

            $output->writeln("<info>You have selected the following PHP version: </info><fg=blue>$phpVersion</fg=blue>");
        }

        return $phpVersion;
    }
}
