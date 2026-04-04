<?php

namespace Tests\Unit;

use App\Services\Buddy\CompanionSprites;
use App\Services\Buddy\CompanionTypes;
use PHPUnit\Framework\TestCase;

class CompanionSpritesTest extends TestCase
{
    public function test_render_returns_five_lines(): void
    {
        foreach (CompanionTypes::SPECIES as $species) {
            $lines = CompanionSprites::render($species, '·', 0);
            $this->assertCount(5, $lines, "Species {$species} should render 5 lines");
        }
    }

    public function test_render_substitutes_eye_character(): void
    {
        $lines = CompanionSprites::render('duck', '✦', 0);
        $fullSprite = implode("\n", $lines);
        $this->assertStringContainsString('✦', $fullSprite, 'Eye character should be substituted');
        $this->assertStringNotContainsString('{E}', $fullSprite, 'Placeholder should be replaced');
    }

    public function test_render_different_eye_styles(): void
    {
        foreach (CompanionTypes::EYES as $eye) {
            $lines = CompanionSprites::render('cat', $eye, 0);
            $fullSprite = implode("\n", $lines);
            $this->assertStringContainsString($eye, $fullSprite, "Eye '{$eye}' should appear in sprite");
        }
    }

    public function test_render_supports_three_frames(): void
    {
        for ($frame = 0; $frame < 3; $frame++) {
            $lines = CompanionSprites::render('dragon', '·', $frame);
            $this->assertCount(5, $lines, "Frame {$frame} should have 5 lines");
        }
    }

    public function test_render_frames_are_different(): void
    {
        $frame0 = CompanionSprites::render('goose', '◉', 0);
        $frame1 = CompanionSprites::render('goose', '◉', 1);
        $frame2 = CompanionSprites::render('goose', '◉', 2);

        // At least some frames should differ
        $allSame = ($frame0 === $frame1 && $frame1 === $frame2);
        $this->assertFalse($allSame, 'Animation frames should differ');
    }

    public function test_get_hat_lines_returns_correct_count(): void
    {
        $noHat = CompanionSprites::getHatLines('none');
        $this->assertEmpty($noHat, 'none hat should return empty array');

        $crown = CompanionSprites::getHatLines('crown');
        $this->assertNotEmpty($crown, 'crown hat should return lines');
        $this->assertLessThanOrEqual(2, count($crown), 'Hat should be at most 2 lines');
    }

    public function test_hat_lines_are_centered_on_12_chars(): void
    {
        $hats = ['crown', 'tophat', 'propeller', 'halo', 'wizard', 'beanie', 'tinyduck'];
        foreach ($hats as $hat) {
            $lines = CompanionSprites::getHatLines($hat);
            foreach ($lines as $line) {
                $this->assertLessThanOrEqual(12, mb_strlen($line), "Hat {$hat} line too wide");
            }
        }
    }

    public function test_sprite_lines_are_within_width(): void
    {
        foreach (CompanionTypes::SPECIES as $species) {
            for ($frame = 0; $frame < 3; $frame++) {
                $lines = CompanionSprites::render($species, '·', $frame);
                foreach ($lines as $i => $line) {
                    $this->assertLessThanOrEqual(12, mb_strlen($line),
                        "Species {$species} frame {$frame} line {$i} exceeds 12 chars (got {$line})");
                }
            }
        }
    }

    // ─── renderFace ──────────────────────────────────────────────────────

    public function test_render_face_returns_string_for_all_species(): void
    {
        foreach (CompanionTypes::SPECIES as $species) {
            $face = CompanionSprites::renderFace($species, '·');
            $this->assertIsString($face, "Face for {$species} should be a string");
            $this->assertNotEmpty($face, "Face for {$species} should not be empty");
        }
    }

    public function test_render_face_substitutes_eye(): void
    {
        $face = CompanionSprites::renderFace('cat', '✦');
        $this->assertStringContainsString('✦', $face);
        $this->assertStringNotContainsString('{E}', $face);
    }

    public function test_render_face_duck_format(): void
    {
        $face = CompanionSprites::renderFace('duck', '·');
        $this->assertSame('(·>', $face);
    }

    public function test_render_face_cat_format(): void
    {
        $face = CompanionSprites::renderFace('cat', '·');
        $this->assertSame('=·ω·=', $face);
    }

    public function test_render_face_different_eyes_produce_different_results(): void
    {
        $face1 = CompanionSprites::renderFace('dragon', '·');
        $face2 = CompanionSprites::renderFace('dragon', '✦');
        $this->assertNotSame($face1, $face2);
    }

    // ─── renderBlink ─────────────────────────────────────────────────────

    public function test_render_blink_returns_five_lines(): void
    {
        foreach (CompanionTypes::SPECIES as $species) {
            $lines = CompanionSprites::renderBlink($species, '·');
            $this->assertCount(5, $lines, "Blink for {$species} should have 5 lines");
        }
    }

    public function test_render_blink_replaces_eyes_with_dashes(): void
    {
        $lines = CompanionSprites::renderBlink('cat', '·');
        $fullSprite = implode("\n", $lines);
        // Original eye should be replaced
        $this->assertStringNotContainsString('·', $fullSprite);
        // Dash should appear where eye was
        $this->assertStringContainsString('-', $fullSprite);
    }

    public function test_render_blink_is_different_from_normal(): void
    {
        $normal = CompanionSprites::render('cat', '·', 0);
        $blink = CompanionSprites::renderBlink('cat', '·');
        $this->assertNotSame($normal, $blink);
    }
}
