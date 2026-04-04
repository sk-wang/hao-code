<?php

declare(strict_types=1);

namespace App\Services\Buddy;

final class CompanionRoller
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * Roll a companion for the given userId using the default salt.
     *
     * @return array{rarity: string, species: string, eye: string, hat: string, shiny: bool, stats: array<string, int>}
     */
    public static function roll(string $userId): array
    {
        return static::rollWithSeed($userId . 'friend-2026-401');
    }

    /**
     * Roll a companion from an arbitrary seed string (useful for testing).
     *
     * @return array{rarity: string, species: string, eye: string, hat: string, shiny: bool, stats: array<string, int>}
     */
    public static function rollWithSeed(string $seed): array
    {
        if (isset(static::$cache[$seed])) {
            return static::$cache[$seed];
        }

        $hash = static::hashString($seed);
        $rng = static::createPrng($hash);

        $rarity = static::rollRarity($rng);
        $species = CompanionTypes::SPECIES[static::nextBounded($rng, count(CompanionTypes::SPECIES))];
        $eye = CompanionTypes::EYES[static::nextBounded($rng, count(CompanionTypes::EYES))];
        $hat = $rarity === 'common' ? 'none' : CompanionTypes::HATS[static::nextBounded($rng, count(CompanionTypes::HATS))];
        $shiny = static::nextBounded($rng, 100) < 1;

        $stats = static::rollStats($rng, $rarity);

        $result = [
            'rarity'  => $rarity,
            'species' => $species,
            'eye'     => $eye,
            'hat'     => $hat,
            'shiny'   => $shiny,
            'stats'   => $stats,
        ];

        static::$cache[$seed] = $result;

        return $result;
    }

    /**
     * FNV-1a hash, returning an unsigned 32-bit integer.
     */
    private static function hashString(string $str): int
    {
        $h = 2166136261;
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $h ^= ord($str[$i]);
            // Multiply by 16777619, keeping within 32-bit unsigned range.
            $h = (($h & 0xFFFFFFFF) * 16777619) & 0xFFFFFFFF;
        }

        return $h & 0xFFFFFFFF;
    }

    /**
     * Mulberry32 PRNG. Returns a closure that yields a 32-bit unsigned int on each call.
     *
     * @return \Closure: int
     */
    private static function createPrng(int $seed): \Closure
    {
        return function () use (&$seed): int {
            $seed = ($seed + 0x6D2B79F5) & 0xFFFFFFFF;
            $t = $seed;
            $t = static::mul32($t ^ ($t >> 15), $t | 1);
            $t = ($t + static::mul32($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF;

            return ($t ^ ($t >> 14)) & 0xFFFFFFFF;
        };
    }

    /**
     * Get a random int in [0, $bound) from the PRNG.
     */
    private static function nextBounded(\Closure $rng, int $bound): int
    {
        if ($bound <= 1) {
            return 0;
        }

        return intdiv($rng() * $bound, 0x100000000);
    }

    /**
     * Multiply two unsigned 32-bit integers and keep only the low 32 bits.
     */
    private static function mul32(int $a, int $b): int
    {
        $aLow = $a & 0xFFFF;
        $aHigh = ($a >> 16) & 0xFFFF;
        $bLow = $b & 0xFFFF;
        $bHigh = ($b >> 16) & 0xFFFF;

        $low = $aLow * $bLow;
        $mid = ($aHigh * $bLow) + ($aLow * $bHigh);

        return ($low + (($mid & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }

    /**
     * Roll the rarity tier using weighted probabilities.
     */
    private static function rollRarity(\Closure $rng): string
    {
        $totalWeight = array_sum(CompanionTypes::RARITY_WEIGHTS);
        $roll = static::nextBounded($rng, $totalWeight);

        $cumulative = 0;
        foreach (CompanionTypes::RARITY_WEIGHTS as $rarity => $weight) {
            $cumulative += $weight;
            if ($roll < $cumulative) {
                return $rarity;
            }
        }

        return 'common';
    }

    /**
     * Roll five stats: one peak, one dump, rest scattered. Rarity bumps the floor.
     *
     * @return array<string, int>
     */
    private static function rollStats(\Closure $rng, string $rarity): array
    {
        $floor = CompanionTypes::RARITY_FLOOR[$rarity];
        $statNames = CompanionTypes::STAT_NAMES;

        // Pick peak and dump indices
        $peakIndex = static::nextBounded($rng, count($statNames));
        do {
            $dumpIndex = static::nextBounded($rng, count($statNames));
        } while ($dumpIndex === $peakIndex);

        $stats = [];
        foreach ($statNames as $i => $name) {
            if ($i === $peakIndex) {
                // Peak: 70-100
                $stats[$name] = 70 + static::nextBounded($rng, 31);
            } elseif ($i === $dumpIndex) {
                // Dump: floor to floor+20
                $stats[$name] = $floor + static::nextBounded($rng, min(21, 101 - $floor));
            } else {
                // Scattered: floor+10 to 60
                $min = $floor + 10;
                $max = 61;
                if ($min >= $max) {
                    $stats[$name] = $min;
                } else {
                    $stats[$name] = $min + static::nextBounded($rng, $max - $min);
                }
            }

            // Clamp to 0-100
            $stats[$name] = max(0, min(100, $stats[$name]));
        }

        return $stats;
    }
}
