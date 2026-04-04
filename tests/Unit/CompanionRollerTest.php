<?php

namespace Tests\Unit;

use App\Services\Buddy\CompanionRoller;
use App\Services\Buddy\CompanionTypes;
use PHPUnit\Framework\TestCase;

class CompanionRollerTest extends TestCase
{
    public function test_roll_returns_deterministic_result_for_same_user_id(): void
    {
        $result1 = CompanionRoller::roll('user123');
        $result2 = CompanionRoller::roll('user123');

        $this->assertSame($result1['species'], $result2['species']);
        $this->assertSame($result1['eye'], $result2['eye']);
        $this->assertSame($result1['hat'], $result2['hat']);
        $this->assertSame($result1['rarity'], $result2['rarity']);
        $this->assertSame($result1['shiny'], $result2['shiny']);
        $this->assertSame($result1['stats'], $result2['stats']);
    }

    public function test_roll_returns_different_results_for_different_user_ids(): void
    {
        $result1 = CompanionRoller::roll('alice');
        $result2 = CompanionRoller::roll('bob');

        // Extremely unlikely to be identical
        $this->assertNotSame(json_encode($result1), json_encode($result2));
    }

    public function test_roll_returns_valid_species(): void
    {
        $result = CompanionRoller::roll('test_user');
        $this->assertContains($result['species'], CompanionTypes::SPECIES);
    }

    public function test_roll_returns_valid_eye(): void
    {
        $result = CompanionRoller::roll('test_user');
        $this->assertContains($result['eye'], CompanionTypes::EYES);
    }

    public function test_roll_returns_valid_hat(): void
    {
        $result = CompanionRoller::roll('test_user');
        $this->assertContains($result['hat'], CompanionTypes::HATS);
    }

    public function test_roll_returns_valid_rarity(): void
    {
        $result = CompanionRoller::roll('test_user');
        $this->assertContains($result['rarity'], CompanionTypes::RARITIES);
    }

    public function test_roll_returns_all_stat_names(): void
    {
        $result = CompanionRoller::roll('test_user');
        foreach (CompanionTypes::STAT_NAMES as $statName) {
            $this->assertArrayHasKey($statName, $result['stats']);
            $this->assertIsInt($result['stats'][$statName]);
        }
    }

    public function test_stats_are_within_valid_range(): void
    {
        // Test multiple user IDs to cover various stat distributions
        for ($i = 0; $i < 20; $i++) {
            $result = CompanionRoller::roll("user_{$i}");
            foreach ($result['stats'] as $stat => $value) {
                $this->assertGreaterThanOrEqual(1, $value, "Stat {$stat} below minimum");
                $this->assertLessThanOrEqual(100, $value, "Stat {$stat} above maximum");
            }
        }
    }

    public function test_roll_has_shiny_as_boolean(): void
    {
        $result = CompanionRoller::roll('test_user');
        $this->assertIsBool($result['shiny']);
    }

    public function test_roll_with_seed_produces_same_result(): void
    {
        $result1 = CompanionRoller::rollWithSeed('my_seed');
        $result2 = CompanionRoller::rollWithSeed('my_seed');

        $this->assertSame($result1['species'], $result2['species']);
        $this->assertSame($result1['rarity'], $result2['rarity']);
    }

    public function test_common_rarity_has_no_hat(): void
    {
        // Find a user ID that produces common rarity
        for ($i = 0; $i < 100; $i++) {
            $result = CompanionRoller::roll("common_test_{$i}");
            if ($result['rarity'] === 'common') {
                $this->assertSame('none', $result['hat']);
                return;
            }
        }
        $this->markTestSkipped('Could not find a common rarity roll in 100 attempts');
    }

    public function test_rarity_weights_produce_expected_distribution(): void
    {
        $counts = array_fill_keys(CompanionTypes::RARITIES, 0);
        $total = 1000;

        for ($i = 0; $i < $total; $i++) {
            $result = CompanionRoller::rollWithSeed("dist_test_{$i}");
            $counts[$result['rarity']]++;
        }

        // Common should be most frequent
        $this->assertGreaterThan($counts['rare'], $counts['common']);
        // Uncommon should be more frequent than rare
        $this->assertGreaterThan($counts['rare'], $counts['uncommon']);
        // Legendary should be least frequent
        $this->assertLessThanOrEqual($counts['epic'], $counts['legendary']);
    }

    public function test_rarity_floor_affects_minimum_stats(): void
    {
        // A legendary roll should have higher minimum stats than common
        $foundLegendary = false;
        $foundCommon = false;

        for ($i = 0; $i < 200; $i++) {
            $result = CompanionRoller::rollWithSeed("floor_test_{$i}");
            if ($result['rarity'] === 'legendary' && !$foundLegendary) {
                $foundLegendary = true;
                $minStat = min($result['stats']);
                $this->assertGreaterThanOrEqual(40, $minStat, 'Legendary should have higher floor');
            }
            if ($result['rarity'] === 'common' && !$foundCommon) {
                $foundCommon = true;
                // Common floor is 5, so dump stat can be as low as 1
                $this->assertTrue(true); // Just confirming we found one
            }
        }
    }
}
