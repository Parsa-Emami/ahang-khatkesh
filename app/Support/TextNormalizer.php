<?php

namespace App\Support;

final class TextNormalizer
{
    public static function normalize(string $value): string
    {
        $value = strtr(mb_strtolower($value), ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', "\u{200C}" => ' ', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}ـ]/u', '', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    public static function like(string $value): string
    {
        return '%'.strtr(self::normalize($value), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
    }
}
