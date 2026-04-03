<?php

namespace App\Support\Terminal;

class InputSanitizer
{
    public static function sanitize(string $input): string
    {
        if (mb_check_encoding($input, 'UTF-8')) {
            return $input;
        }

        $sanitized = iconv('UTF-8', 'UTF-8//IGNORE', $input);

        return $sanitized === false ? '' : $sanitized;
    }
}
