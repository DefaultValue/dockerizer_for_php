<?php

declare(strict_types=1);

namespace App\Service;

class DomainValidator
{
    /**
     * https://dunglas.fr/2014/11/php-7-introducing-a-domain-name-validator-and-making-the-url-validator-stricter/
     *
     * @param string $string
     * @return bool|int
     */
    public function isValid(string $string)
    {
        return filter_var($string, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            && preg_match('@\.(.*[A-Za-z])@', $string);
    }
}
