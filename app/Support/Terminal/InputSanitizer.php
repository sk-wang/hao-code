<?php

namespace App\Support\Terminal;

class InputSanitizer
{
    public static function sanitize(string $input): string
    {
        if ($input === '') {
            return '';
        }

        if (mb_check_encoding($input, 'UTF-8')) {
            return $input;
        }

        set_error_handler(static fn () => true);
        try {
            $sanitized = iconv('UTF-8', 'UTF-8//IGNORE', $input);
        } finally {
            restore_error_handler();
        }

        return $sanitized === false ? '' : $sanitized;
    }
}
