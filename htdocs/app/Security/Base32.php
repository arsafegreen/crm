<?php

declare(strict_types=1);

namespace App\Security;

final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        $chunk = 5;
        for ($i = 0, $len = strlen($binary); $i < $len; $i += $chunk) {
            $segment = substr($binary, $i, $chunk);
            if (strlen($segment) < $chunk) {
                $segment = str_pad($segment, $chunk, '0', STR_PAD_RIGHT);
            }
            $index = bindec($segment);
            $output .= self::ALPHABET[$index];
        }

        $padding = (8 - (int)(ceil(strlen($data) * 8 / $chunk)) % 8) % 8;
        return $output . str_repeat('=', $padding);
    }

    public static function decode(string $input): string
    {
        $input = strtoupper(trim($input, '='));
        if ($input === '') {
            return '';
        }

        $binary = '';
        foreach (str_split($input) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position === false) {
                throw new \InvalidArgumentException('Base32 inválido.');
            }
            $binary .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        for ($i = 0, $len = strlen($binary); $i < $len; $i += 8) {
            $segment = substr($binary, $i, 8);
            if (strlen($segment) < 8) {
                continue;
            }
            $output .= chr(bindec($segment));
        }

        return $output;
    }
}
