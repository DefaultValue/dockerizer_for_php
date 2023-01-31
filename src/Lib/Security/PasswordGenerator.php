<?php

/*
 * Copyright (c) Default Value LLC.
 * This source file is subject to the License https://github.com/DefaultValue/dockerizer_for_php/LICENSE.txt
 * Do not change this file if you want to upgrade the tool to the newer versions in the future
 * Please, contact us at https://default-value.com/#contact if you wish to customize this tool
 * according to you business needs
 */

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Lib\Security;

class PasswordGenerator
{
    /**
     * Unsupported chars:
     * - single quote - '
     * - double quote - "
     * - backslash - \
     * These chars cause issues in the escape sequences, especially in MySQL passwords
     * when these paswords are used for MYSQL_PASSWORD or other similar variables
     */
    private const CHARACTER_SET_LOWERCASE_LETTERS = 'abcdefghijklmnopqrstuvwxyz';
    private const CHARACTER_SET_UPPERCASE_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const CHARACTER_SET_DIGITS = '1234567890';
    private const CHARACTER_SET_SPECIAL_CHARS = '`~!@#$%^&*()_-+={}[]/<>,.;?:|';

    /**
     * @param int $length
     * @return string
     * @throws \Exception
     */
    public function generatePassword(int $length = 16): string
    {
        $charSets = [
            self::CHARACTER_SET_LOWERCASE_LETTERS,
            self::CHARACTER_SET_UPPERCASE_LETTERS,
            self::CHARACTER_SET_DIGITS,
            self::CHARACTER_SET_SPECIAL_CHARS
        ];

        $password = '';

        foreach ($charSets as $set) {
            $password .= $set[random_int(0, count(str_split($set)) - 1)];
        }

        $allChars = str_split(implode($charSets));

        while (strlen($password) < $length) {
            $password .= $allChars[random_int(0, count($allChars) - 1)];
            str_replace('\$', '', $password);
        }

        return $password;
    }
}
