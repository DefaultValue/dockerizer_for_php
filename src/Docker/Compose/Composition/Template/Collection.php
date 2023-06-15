<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Docker\Compose\Composition\Template;

use Composer\Semver\Semver;

/**
 * Collection of Docker composition templates from ./templates/services/ or from the repositories
 */
class Collection extends \DefaultValue\Dockerizer\Filesystem\ProcessibleFile\AbstractFileCollection
{
    public const PROCESSABLE_FILE_INSTANCE = \DefaultValue\Dockerizer\Docker\Compose\Composition\Template::class;

    /**
     * @param string $packageName
     * @param string $packageVersion
     * @return array
     */
    public function getRecommendedTemplates(string $packageName, string $packageVersion): array
    {
        $templatesList = [];

        foreach ($this->getItems() as $template) {
            $supportedPackages = $template->getSupportedPackages();

            if (!isset($supportedPackages[$packageName])) {
                continue;
            }

            if (Semver::satisfies($packageVersion, $supportedPackages[$packageName])) {
                $templatesList[$template->getCode()] = $template;
            }
        }

        return $templatesList;
    }

    /**
     * @param array $requiredProjectPackages
     * @return array
     */
    public function getSuitableTemplates(array $requiredProjectPackages): array
    {
        $templatesList = [];

        foreach ($this->getItems() as $template) {
            $suitableFor = [];

            foreach ($template->getSupportedPackages() as $supportedPackage => $versionConstraint) {
                if (!isset($requiredProjectPackages[$supportedPackage])) {
                    continue;
                }

                if (Semver::satisfies($requiredProjectPackages[$supportedPackage], $versionConstraint)) {
                    $suitableFor[] = $supportedPackage;
                }
            }

            if ($suitableFor) {
                $templatesList[$template->getCode()] = $suitableFor;
            }
        }

        return $templatesList;
    }
}
