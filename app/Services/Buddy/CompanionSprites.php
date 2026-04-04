<?php

declare(strict_types=1);

namespace App\Services\Buddy;

final class CompanionSprites
{
    private const SPRITES = [
        'duck' => [
            ['            ','    __      ','  <({E} )___  ','   (  ._>   ','    `--´    '],
            ['            ','    __      ','  <({E} )___  ','   (  ._>   ','    `--´~   '],
            ['            ','    __      ','  <({E} )___  ','   (  .__>  ','    `--´    '],
        ],
        'goose' => [
            ['            ','     ({E}>    ','     ||     ','   _(__)_   ','    ^^^^    '],
            ['            ','    ({E}>     ','     ||     ','   _(__)_   ','    ^^^^    '],
            ['            ','     ({E}>>   ','     ||     ','   _(__)_   ','    ^^^^    '],
        ],
        'blob' => [
            ['            ','   .----.   ','  ( {E}  {E} )  ','  (      )  ','   `----´   '],
            ['            ','  .------.  ',' (  {E}  {E}  ) ',' (        ) ','  `------´  '],
            ['            ','    .--.    ','   ({E}  {E})   ','   (    )   ','    `--´    '],
        ],
        'cat' => [
            ['            ','   /\\_/\\    ','  ( {E}   {E})  ','  (  ω  )   ','  (")_(")   '],
            ['            ','   /\\_/\\    ','  ( {E}   {E})  ','  (  ω  )   ','  (")_(")~  '],
            ['            ','   /\\-/\\    ','  ( {E}   {E})  ','  (  ω  )   ','  (")_(")   '],
        ],
        'dragon' => [
            ['            ','  /^\\  /^\\  ',' <  {E}  {E}  > ',' (   ~~   ) ','  `-vvvv-´  '],
            ['            ','  /^\\  /^\\  ',' <  {E}  {E}  > ',' (        ) ','  `-vvvv-´  '],
            ['   ~    ~   ','  /^\\  /^\\  ',' <  {E}  {E}  > ',' (   ~~   ) ','  `-vvvv-´  '],
        ],
        'octopus' => [
            ['            ','   .----.   ','  ( {E}  {E} )  ','  (______)  ','  /\\/\\/\\/\\  '],
            ['            ','   .----.   ','  ( {E}  {E} )  ','  (______)  ',' /\\/\\/\\/\\/\\ '],
            ['            ','  .------.  ',' (  {E}  {E}  ) ',' (________) ','  \\/\\/\\/\\/  '],
        ],
        'owl' => [
            ['            ','    /\\_/\\   ','   ( {E} {E} )  ','   ( oo )   ','   \\|=|/   '],
            ['            ','    /\\_/\\   ','   ( {E} {E} )  ','   ( oo )   ','  --\\|=|/  '],
            ['            ','   /\\-/\\   ','  (  {E} {E} ) ','  ( oo  )  ','  \\|=|/  '],
        ],
        'penguin' => [
            ['            ','    _.._    ','   ( {E}  {E})  ','  (  <>  )  ','   || ||   '],
            ['            ','    _.._    ','   ( {E}  {E})  ','  (  <>  )  ','  /| || |\\ '],
            ['            ','    _.._    ','   ( {E}  {E})  ','  (  <>  )  ','  \\| || |/ '],
        ],
        'turtle' => [
            ['            ','   .----.   ','  / {E}  {E} \\  ','  \\ ____ /  ','   \'----\'   '],
            ['            ','   .----.   ','  / {E}  {E} \\  ','  \\ ____ /  ','  -\'----\'-  '],
            ['            ','  .------.  ',' /  {E}  {E}  \\ ',' \\ ____  / ','  \'------\'  '],
        ],
        'snail' => [
            ['            ','   .----.   ','  ( {E}  {E} )  ',' _(_/___)  ','  (______)  '],
            ['            ','   .----.   ','  ( {E}  {E} )  ',' _(_/___)~ ','  (______)  '],
            ['            ','  .------.  ',' (  {E}  {E}  ) ','__(_/____) ',' (________) '],
        ],
        'ghost' => [
            ['            ','   .----.   ','  ( {E}  {E} )  ','  |      |  ','  |  ||  |  '],
            ['            ','   .----.   ','  ( {E}  {E} )  ','  |      |  ',' |  ||  | '],
            ['            ','  .------.  ',' (  {E}  {E}  ) ',' |       | ',' |  ||   |'],
        ],
        'axolotl' => [
            [' ~  ~  ~    ','   .----.   ','  ( {E}  {E} )  ','  (  __  )  ','   \'----\'   '],
            ['~  ~  ~     ','   .----.   ','  ( {E}  {E} )  ','  (  __  )  ','   \'----\'   '],
            [' ~  ~  ~    ','  .------.  ',' (  {E}  {E}  ) ',' (  ____  ) ','  \'------\'  '],
        ],
        'capybara' => [
            ['            ','   .----.   ','  ( {E}  {E} )  ','  ( oo  )  ','   \'----\'   '],
            ['            ','   .----.   ','  ( {E}  {E} )  ','  ( oo  )  ','  -\'----\'-  '],
            ['            ','  .------.  ',' (  {E}  {E}  ) ',' (  oo   ) ','  \'------\'  '],
        ],
        'cactus' => [
            ['     |      ','   __|__    ','  ( {E}  {E} )  ','  |    |    ','  |____|    '],
            ['    |       ','   __|__    ','  ( {E}  {E} )  ','  |    |    ','  |____|    '],
            ['   |   |    ','   __|__    ','  ( {E}  {E} )  ','  |    |    ','  |____|    '],
        ],
        'robot' => [
            ['            ','   .----.   ','  [{E}    {E}]  ','  | == |    ','  \'----\'   '],
            ['            ','   .----.   ','  [{E}    {E}]  ','  | == |    ','  -\'----\'-  '],
            ['            ','   .----.   ','  [{E}  * {E}]  ','  | == |    ','  \'----\'   '],
        ],
        'rabbit' => [
            ['    /\\  /\\  ','   /{E}\\/{E} \\ ','  (  <>  )  ','  (  \'\'  )  ','   \'----\'   '],
            ['   /\\  /\\   ','  /{E}\\/{E} \\  ','  (  <>  )  ','  (  \'\'  )  ','   \'----\'   '],
            ['    /\\  /\\  ','   /{E}\\/{E} \\ ','  (  <>  )  ','  (  \'\'  )  ','  -\'----\'-  '],
        ],
        'mushroom' => [
            ['            ','   .----.   ','  / {E}  {E} \\  ','  |    |    ','  |____|    '],
            ['            ','  .------.  ',' /  {E}  {E}  \\ ','  |    |    ','  |____|    '],
            ['            ','   .----.   ','  / {E}  {E} \\  ','  |    |    ','  |____|    '],
        ],
        'chonk' => [
            ['            ','  .------.  ',' (  {E}  {E}  ) ',' (  ____  ) ','  \'------\'  '],
            ['            ',' .--------. ','(   {E}  {E}   )','(  ______  )',' \'--------\' '],
            ['            ','  .------.  ',' (  {E}  {E}  ) ',' (  ____  ) ','  \'----\'~   '],
        ],
    ];

    private const FACES = [
        'duck'      => '({E}>',
        'goose'     => '({E}>',
        'blob'      => '({E} {E})',
        'cat'       => '={E}ω{E}=',
        'dragon'    => '<{E} {E}>',
        'octopus'   => '({E} {E})',
        'owl'       => '({E} {E})',
        'penguin'   => '({E} {E})',
        'turtle'    => '/{E} {E}\\',
        'snail'     => '({E} {E})',
        'ghost'     => '({E} {E})',
        'axolotl'   => '({E} {E})',
        'capybara'  => '({E} {E})',
        'cactus'    => '({E} {E})',
        'robot'     => '[{E}  {E}]',
        'rabbit'    => '/{E}\\/{E}\\',
        'mushroom'  => '/{E} {E}\\',
        'chonk'     => '({E} {E})',
    ];

    private const HATS = [
        'none'      => [],
        'crown'     => ['    ^^^^    ','   (❦)_(❦)  '],
        'tophat'    => ['   .----.   ','   |    |   '],
        'propeller' => ['   /-~~-\\   ','    ====    '],
        'halo'      => ['    ___     ','   ( o )    '],
        'wizard'    => ['    /\\     ','   /  \\    '],
        'beanie'    => ['   .----.   ','   |~~~~|   '],
        'tinyduck'  => ['    __      ','   (><)     '],
    ];

    /**
     * Render a species sprite for the given frame, substituting the eye character.
     *
     * @return array<int, string>  5 lines of ASCII art (without hat)
     */
    public static function render(string $species, string $eye, int $frame = 0): array
    {
        if (! isset(static::SPRITES[$species])) {
            throw new \InvalidArgumentException("Unknown species: {$species}");
        }

        $frames = static::SPRITES[$species];
        $frameIndex = $frame % count($frames);
        $lines = $frames[$frameIndex];

        return array_map(
            static fn (string $line): string => str_replace('{E}', $eye, $line),
            $lines,
        );
    }

    /**
     * Get hat overlay lines (2 lines max, centered on 12 chars). Empty array for 'none'.
     *
     * @return array<int, string>
     */
    public static function getHatLines(string $hat): array
    {
        return static::HATS[$hat] ?? [];
    }

    /**
     * Render a compact one-line face for the given species.
     * E.g. duck → "(·>", cat → "=·ω·=", dragon → "<· ·>"
     */
    public static function renderFace(string $species, string $eye): string
    {
        if (!isset(static::FACES[$species])) {
            return "({$eye}{$eye})";
        }

        return str_replace('{E}', $eye, static::FACES[$species]);
    }

    /**
     * Render a blink frame (eyes replaced with dashes) for the given species.
     *
     * @return array<int, string>  5 lines of ASCII art
     */
    public static function renderBlink(string $species, string $eye): array
    {
        $lines = static::render($species, $eye, 0);

        // Replace the eye character with a dash to create a blink
        return array_map(
            static fn (string $line): string => str_replace($eye, '-', $line),
            $lines,
        );
    }
}
