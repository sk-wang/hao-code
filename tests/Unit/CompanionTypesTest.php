<?php

namespace Tests\Unit;

use App\Services\Buddy\CompanionTypes;
use PHPUnit\Framework\TestCase;

class CompanionTypesTest extends TestCase
{
    public function test_species_list_has_18_entries(): void
    {
        $this->assertCount(18, CompanionTypes::SPECIES);
    }

    public function test_species_list_contains_expected_entries(): void
    {
        $expected = ['duck', 'goose', 'blob', 'cat', 'dragon', 'octopus', 'owl', 'penguin',
            'turtle', 'snail', 'ghost', 'axolotl', 'capybara', 'cactus', 'robot',
            'rabbit', 'mushroom', 'chonk'];

        foreach ($expected as $species) {
            $this->assertContains($species, CompanionTypes::SPECIES, "Missing species: {$species}");
        }
    }

    public function test_eyes_list_has_six_entries(): void
    {
        $this->assertCount(6, CompanionTypes::EYES);
    }

    public function test_hats_list_has_eight_entries(): void
    {
        $this->assertCount(8, CompanionTypes::HATS);
    }

    public function test_hats_includes_none(): void
    {
        $this->assertContains('none', CompanionTypes::HATS);
    }

    public function test_rarities_list_has_five_entries(): void
    {
        $this->assertCount(5, CompanionTypes::RARITIES);
    }

    public function test_rarity_weights_sum_to_100(): void
    {
        $sum = array_sum(CompanionTypes::RARITY_WEIGHTS);
        $this->assertSame(100, $sum);
    }

    public function test_rarity_weights_cover_all_rarities(): void
    {
        foreach (CompanionTypes::RARITIES as $rarity) {
            $this->assertArrayHasKey($rarity, CompanionTypes::RARITY_WEIGHTS);
            $this->assertGreaterThan(0, CompanionTypes::RARITY_WEIGHTS[$rarity]);
        }
    }

    public function test_rarity_stars_cover_all_rarities(): void
    {
        foreach (CompanionTypes::RARITIES as $rarity) {
            $this->assertArrayHasKey($rarity, CompanionTypes::RARITY_STARS);
            $this->assertNotEmpty(CompanionTypes::RARITY_STARS[$rarity]);
        }
    }

    public function test_rarity_stars_increase_with_rarity(): void
    {
        $this->assertSame('★', CompanionTypes::RARITY_STARS['common']);
        $this->assertSame('★★', CompanionTypes::RARITY_STARS['uncommon']);
        $this->assertSame('★★★', CompanionTypes::RARITY_STARS['rare']);
        $this->assertSame('★★★★', CompanionTypes::RARITY_STARS['epic']);
        $this->assertSame('★★★★★', CompanionTypes::RARITY_STARS['legendary']);
    }

    public function test_rarity_colors_cover_all_rarities(): void
    {
        foreach (CompanionTypes::RARITIES as $rarity) {
            $this->assertArrayHasKey($rarity, CompanionTypes::RARITY_COLORS);
            $this->assertIsString(CompanionTypes::RARITY_COLORS[$rarity]);
        }
    }

    public function test_rarity_floors_increase_with_rarity(): void
    {
        $this->assertLessThan(
            CompanionTypes::RARITY_FLOOR['uncommon'],
            CompanionTypes::RARITY_FLOOR['common']
        );
        $this->assertLessThan(
            CompanionTypes::RARITY_FLOOR['rare'],
            CompanionTypes::RARITY_FLOOR['uncommon']
        );
        $this->assertLessThan(
            CompanionTypes::RARITY_FLOOR['epic'],
            CompanionTypes::RARITY_FLOOR['rare']
        );
        $this->assertLessThan(
            CompanionTypes::RARITY_FLOOR['legendary'],
            CompanionTypes::RARITY_FLOOR['epic']
        );
    }

    public function test_stat_names_has_five_entries(): void
    {
        $this->assertCount(5, CompanionTypes::STAT_NAMES);
    }

    public function test_stat_names_contains_expected_stats(): void
    {
        $expected = ['DEBUGGING', 'PATIENCE', 'CHAOS', 'WISDOM', 'SNARK'];
        foreach ($expected as $stat) {
            $this->assertContains($stat, CompanionTypes::STAT_NAMES);
        }
    }

    public function test_rarity_weights_are_ordered_correctly(): void
    {
        // Common should be most likely, legendary least likely
        $this->assertGreaterThan(
            CompanionTypes::RARITY_WEIGHTS['uncommon'],
            CompanionTypes::RARITY_WEIGHTS['common']
        );
        $this->assertGreaterThan(
            CompanionTypes::RARITY_WEIGHTS['legendary'],
            CompanionTypes::RARITY_WEIGHTS['epic']
        );
    }
}
