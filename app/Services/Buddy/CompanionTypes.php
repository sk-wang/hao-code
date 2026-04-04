<?php

declare(strict_types=1);

namespace App\Services\Buddy;

final class CompanionTypes
{
    public const SPECIES = [
        'duck', 'goose', 'blob', 'cat', 'dragon', 'octopus',
        'owl', 'penguin', 'turtle', 'snail', 'ghost', 'axolotl',
        'capybara', 'cactus', 'robot', 'rabbit', 'mushroom', 'chonk',
    ];

    public const EYES = ['·', '✦', '×', '◉', '@', '°'];

    public const HATS = ['none', 'crown', 'tophat', 'propeller', 'halo', 'wizard', 'beanie', 'tinyduck'];

    public const RARITIES = ['common', 'uncommon', 'rare', 'epic', 'legendary'];

    public const RARITY_WEIGHTS = [
        'common'    => 60,
        'uncommon'  => 25,
        'rare'      => 10,
        'epic'      => 4,
        'legendary' => 1,
    ];

    public const STAT_NAMES = ['DEBUGGING', 'PATIENCE', 'CHAOS', 'WISDOM', 'SNARK'];

    public const RARITY_STARS = [
        'common'    => '★',
        'uncommon'  => '★★',
        'rare'      => '★★★',
        'epic'      => '★★★★',
        'legendary' => '★★★★★',
    ];

    public const RARITY_COLORS = [
        'common'    => 'gray',
        'uncommon'  => 'green',
        'rare'      => 'cyan',
        'epic'      => 'magenta',
        'legendary' => 'yellow',
    ];

    public const RARITY_FLOOR = [
        'common'    => 5,
        'uncommon'  => 15,
        'rare'      => 25,
        'epic'      => 35,
        'legendary' => 50,
    ];
}
