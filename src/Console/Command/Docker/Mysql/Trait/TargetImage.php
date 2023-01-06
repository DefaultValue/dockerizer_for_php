<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\Command\Docker\Mysql\Trait;

use DefaultValue\Dockerizer\Console\Command\Docker\Mysql\GenerateMetadata;
use DefaultValue\Dockerizer\Docker\ContainerizedService\Mysql;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * @TODO: Move this functionality elsewhere. Maybe add more functionality to OptionDefinition or introduce a new class
 * The same option is added in `docker:mysql:generate-metadata` and `docker:mysql:upload-to-aws`
 *
 * @deprecated
 */
trait TargetImage
{
    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param Mysql $mysql
     * @param QuestionHelper $questionHelper
     * @return string
     */
    private function getTargetImage(
        InputInterface $input,
        OutputInterface $output,
        Mysql $mysql,
        QuestionHelper $questionHelper
    ): string {
        // Get from command parameters
        if ($targetImage = (string) $input->getOption('target-image')) {
            return $targetImage;
        }

        if ($targetImage = $mysql->getLabel(GenerateMetadata::CONTAINER_LABEL_TARGET_REGISTRY)) {
            return $targetImage;
        }

        if (!$input->isInteractive()) {
            throw new \InvalidArgumentException(
                'Use \'--target-image\' option to explicitly pass Docker image name in the non-interactive mode'
            );
        }

        $question = new Question(
            'Enter Docker image name including registry domain (if needed) and excluding tags' . PHP_EOL . '> '
        );
        $question->setTrimmable(true);
        $targetImage = $questionHelper->ask($input, $output, $question);

        if (!$targetImage) {
            throw new \InvalidArgumentException('Target Docker image can\'t be empty');
        }

        $output->writeln(sprintf(
            <<<'EOF'
            Provided Docker image name is: <info>%1$s</info>
            We recommend adding it as one of:
            - A label in '<info>docker run</info>' command: <info>docker run ... --label %2$s=%1$s</info>
            - A label in the '<info>docker-compose.yaml</info>' file: <info>- %2$s=%1$s</info>

            EOF,
            $targetImage,
            GenerateMetadata::CONTAINER_LABEL_TARGET_REGISTRY,
        ));

        return $targetImage;
    }
}
