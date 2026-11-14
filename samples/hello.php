<?php
// Contoh library rahasia yang ingin dilindungi
namespace Acme;

class Secret
{
    /** Algoritma "rahasia" yang tidak ingin dibaca orang lain. */
    public static function compute(int $a, int $b): int
    {
        $magic = 1337;             // konstanta rahasia
        return ($a * $b) + $magic;
    }

    public static function greet(string $name): string
    {
        return "Halo {$name}, hasil = " . self::compute(6, 7);
    }
}
