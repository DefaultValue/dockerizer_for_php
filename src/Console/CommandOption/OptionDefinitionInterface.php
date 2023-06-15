<?php
/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Console\CommandOption;

interface OptionDefinitionInterface
{
    /**
     * For options like --domains='foo.com www.foo.com bar.com www.bar.com baz.com www.baz.com'
     */
    public const VALUE_SEPARATOR = ' ';

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @return string
     */
    public function getShortcut(): string;

    /**
     * @return int
     */
    public function getMode(): int;

    /**
     * @return string
     */
    public function getDescription(): string;

    /**
     * @return mixed
     */
    public function getDefault(): mixed;
}
