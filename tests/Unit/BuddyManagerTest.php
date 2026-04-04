<?php

namespace Tests\Unit;

use App\Services\Buddy\BuddyManager;
use PHPUnit\Framework\TestCase;

class BuddyManagerTest extends TestCase
{
    private string $tmpDir;
    private string $originalHome = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/buddy_test_' . getmypid();
        mkdir($this->tmpDir . '/.haocode', 0755, true);

        $this->originalHome = $_SERVER['HOME'] ?? '';
        $_SERVER['HOME'] = $this->tmpDir;
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            $_SERVER['HOME'] = $this->originalHome;
        } else {
            unset($_SERVER['HOME']);
        }

        $file = $this->tmpDir . '/.haocode/buddy.json';
        if (file_exists($file)) {
            unlink($file);
        }
        if (is_dir($this->tmpDir . '/.haocode')) {
            rmdir($this->tmpDir . '/.haocode');
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // ─── Basic hatch / isHatched / getCompanion ──────────────────────────

    public function test_new_manager_is_not_hatched(): void
    {
        $manager = new BuddyManager();
        $this->assertFalse($manager->isHatched());
    }

    public function test_get_companion_returns_null_when_not_hatched(): void
    {
        $manager = new BuddyManager();
        $this->assertNull($manager->getCompanion());
    }

    public function test_hatch_creates_a_companion(): void
    {
        $manager = new BuddyManager();
        $companion = $manager->hatch('Quacksworth', 'A philosophical duck');

        $this->assertNotNull($companion);
        $this->assertSame('Quacksworth', $companion['name']);
        $this->assertSame('A philosophical duck', $companion['personality']);
        $this->assertTrue($manager->isHatched());
    }

    public function test_hatched_companion_has_valid_species(): void
    {
        $manager = new BuddyManager();
        $companion = $manager->hatch('TestPet', 'Test personality');

        $this->assertContains($companion['species'], \App\Services\Buddy\CompanionTypes::SPECIES);
    }

    public function test_hatched_companion_has_all_required_fields(): void
    {
        $manager = new BuddyManager();
        $companion = $manager->hatch('TestPet', 'Test personality');

        $this->assertArrayHasKey('name', $companion);
        $this->assertArrayHasKey('personality', $companion);
        $this->assertArrayHasKey('species', $companion);
        $this->assertArrayHasKey('eye', $companion);
        $this->assertArrayHasKey('hat', $companion);
        $this->assertArrayHasKey('rarity', $companion);
        $this->assertArrayHasKey('shiny', $companion);
        $this->assertArrayHasKey('stats', $companion);
        $this->assertArrayHasKey('hatched_at', $companion);
        $this->assertArrayHasKey('mood', $companion);
        $this->assertArrayHasKey('muted', $companion);
    }

    public function test_companion_persists_across_instances(): void
    {
        $manager1 = new BuddyManager();
        $manager1->hatch('Persistent', 'Lives forever');

        $manager2 = new BuddyManager();
        $companion = $manager2->getCompanion();

        $this->assertNotNull($companion);
        $this->assertSame('Persistent', $companion['name']);
    }

    public function test_hatch_overwrites_on_second_call(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('First', 'Original');
        $result = $manager->hatch('Second', 'Replaced');

        $this->assertSame('Second', $result['name']);
    }

    public function test_companion_bones_are_deterministic_per_name(): void
    {
        $manager1 = new BuddyManager();
        $manager1->hatch('SameName', 'P1');

        $manager2 = new BuddyManager();
        $manager2->hatch('SameName', 'P2');

        $c1 = $manager1->getCompanion();
        $c2 = $manager2->getCompanion();

        $this->assertSame($c1['species'], $c2['species']);
        $this->assertSame($c1['eye'], $c2['eye']);
        $this->assertSame($c1['hat'], $c2['hat']);
        $this->assertSame($c1['rarity'], $c2['rarity']);
    }

    // ─── Sprite / frame rendering ────────────────────────────────────────

    public function test_get_frame_returns_lines(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Framy', 'Loves animation');

        $frame = $manager->getFrame(0);
        $this->assertNotEmpty($frame);
        $this->assertIsArray($frame);
    }

    public function test_get_frame_returns_empty_when_not_hatched(): void
    {
        $manager = new BuddyManager();
        $this->assertEmpty($manager->getFrame(0));
    }

    public function test_idle_animation_sequence_length(): void
    {
        $this->assertCount(15, BuddyManager::IDLE_SEQUENCE);
    }

    public function test_idle_animation_contains_blink(): void
    {
        $this->assertContains(-1, BuddyManager::IDLE_SEQUENCE);
    }

    public function test_idle_animation_uses_valid_frames(): void
    {
        foreach (BuddyManager::IDLE_SEQUENCE as $frame) {
            $this->assertContains($frame, [-1, 0, 1, 2], "Invalid frame in idle sequence: {$frame}");
        }
    }

    public function test_get_frame_uses_idle_sequence(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Animy', 'An animated one');

        // The idle sequence wraps every 15 ticks
        // Frame at tick 0 and tick 15 (both sequence[0]=0) should match
        $frame0 = $manager->getFrame(0);
        $frame15 = $manager->getFrame(15);
        $this->assertSame($frame0, $frame15);
    }

    public function test_get_face_returns_string(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Facy', 'A face');

        $face = $manager->getFace();
        $this->assertIsString($face);
        $this->assertNotEmpty($face);
    }

    public function test_get_face_returns_empty_when_not_hatched(): void
    {
        $manager = new BuddyManager();
        $this->assertSame('', $manager->getFace());
    }

    // ─── Card display ────────────────────────────────────────────────────

    public function test_get_card_returns_display_lines(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Cardy', 'Loves showing off');

        $card = $manager->getCard();
        $this->assertNotEmpty($card);
        $this->assertIsArray($card);

        $cardText = implode("\n", $card);
        $this->assertStringContainsString('Cardy', $cardText);
    }

    public function test_get_card_returns_message_when_not_hatched(): void
    {
        $manager = new BuddyManager();
        $card = $manager->getCard();
        $this->assertNotEmpty($card);
        $this->assertStringContainsString('No companion hatched', implode('', $card));
    }

    public function test_get_card_includes_mood_emoji(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Moody', 'Expressive');

        $card = $manager->getCard();
        $cardText = implode("\n", $card);
        // Should contain a mood emoji
        $this->assertMatchesRegularExpression('/😊|😟|🎉|🤔|😴/', $cardText);
    }

    public function test_get_narrow_line_returns_string(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Narrow', 'Compact');

        $line = $manager->getNarrowLine();
        $this->assertIsString($line);
        $this->assertNotEmpty($line);
        $this->assertStringContainsString('Narrow', $line);
    }

    public function test_get_narrow_line_shows_quip_when_active(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Quippy', 'Talkative');
        $manager->quip('happy');

        $line = $manager->getNarrowLine();
        // Should contain the quip text instead of name
        $this->assertStringContainsString('Quippy', $line);
    }

    // ─── Speech bubble / quip system ─────────────────────────────────────

    public function test_quip_generates_text(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Quippy', 'Chatty');

        $manager->quip('happy');
        $quip = $manager->getQuip();

        $this->assertNotNull($quip);
        $this->assertStringContainsString('Quippy', $quip);
    }

    public function test_quip_returns_null_when_not_hatched(): void
    {
        $manager = new BuddyManager();
        $manager->quip('happy');
        $this->assertNull($manager->getQuip());
    }

    public function test_quip_fades_after_enough_ticks(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Fadey', 'Temporary');

        $manager->quip('happy');
        $this->assertNotNull($manager->getQuip());

        // Tick through the show + fade period
        $totalTicks = BuddyManager::BUBBLE_SHOW_TICKS + BuddyManager::BUBBLE_FADE_TICKS;
        for ($i = 0; $i < $totalTicks; $i++) {
            $manager->tickQuip();
        }

        $this->assertNull($manager->getQuip());
    }

    public function test_quip_enters_fading_state(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Fadey', 'Temporary');

        $manager->quip('happy');
        $this->assertFalse($manager->isQuipFading());

        // Tick past the show period
        for ($i = 0; $i < BuddyManager::BUBBLE_SHOW_TICKS; $i++) {
            $manager->tickQuip();
        }

        $this->assertTrue($manager->isQuipFading());
    }

    public function test_clear_quip_removes_quip(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Clearable', 'Can be cleared');

        $manager->quip('happy');
        $this->assertNotNull($manager->getQuip());

        $manager->clearQuip();
        $this->assertNull($manager->getQuip());
    }

    public function test_quip_does_not_work_when_muted(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Muted', 'Silent');
        $manager->mute();

        $manager->quip('happy');
        $this->assertNull($manager->getQuip());
    }

    public function test_quip_with_different_moods(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('MoodQuip', 'Versatile');

        foreach (['happy', 'sad', 'excited', 'thinking', 'idle'] as $mood) {
            $manager->quip($mood);
            $quip = $manager->getQuip();
            $this->assertNotNull($quip, "Quip should work for mood: {$mood}");
            $this->assertStringContainsString('MoodQuip', $quip);
            $manager->clearQuip();
        }
    }

    public function test_bubble_constants_are_reasonable(): void
    {
        $this->assertGreaterThan(0, BuddyManager::BUBBLE_SHOW_TICKS);
        $this->assertGreaterThan(0, BuddyManager::BUBBLE_FADE_TICKS);
        $this->assertGreaterThan(0, BuddyManager::PET_HEART_BURST_TICKS);
    }

    // ─── Pet animation ──────────────────────────────────────────────────

    public function test_pet_triggers_hearts(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Pety', 'Loves pets');

        $manager->pet();
        $this->assertTrue($manager->isPetting());

        $hearts = $manager->getPetHearts();
        $this->assertNotNull($hearts);
    }

    public function test_pet_hearts_fade_after_burst(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Pety', 'Loves pets');

        $manager->pet();
        // Consume all heart frames
        for ($i = 0; $i < BuddyManager::PET_HEART_BURST_TICKS; $i++) {
            $manager->getPetHearts();
        }

        $this->assertFalse($manager->isPetting());
    }

    public function test_pet_returns_null_when_not_petting(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Pety', 'Loves pets');

        $this->assertNull($manager->getPetHearts());
    }

    public function test_pet_triggers_excited_quip(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Pety', 'Loves pets');

        $manager->pet();
        $quip = $manager->getQuip();
        $this->assertNotNull($quip);
    }

    public function test_pet_hearts_array_has_expected_count(): void
    {
        $this->assertCount(5, BuddyManager::PET_HEARTS);
    }

    // ─── Mood system ─────────────────────────────────────────────────────

    public function test_get_mood_returns_string(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Moodie', 'Moody');

        $mood = $manager->getMood();
        $this->assertIsString($mood);
        $this->assertNotEmpty($mood);
    }

    public function test_get_mood_detects_error(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Moodie', 'Moody');

        $mood = $manager->getMood('Error: something went wrong');
        $this->assertSame('sad', $mood);
    }

    public function test_get_mood_detects_success(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Moodie', 'Moody');

        $mood = $manager->getMood('Task completed successfully');
        $this->assertSame('excited', $mood);
    }

    public function test_get_mood_detects_thinking(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Moodie', 'Moody');

        $mood = $manager->getMood('Analyzing the code...');
        $this->assertSame('thinking', $mood);
    }

    public function test_get_mood_defaults_to_happy(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Moodie', 'Moody');

        $mood = $manager->getMood('Some random result');
        $this->assertSame('happy', $mood);
    }

    public function test_get_current_mood_returns_stored_mood(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Moodie', 'Moody');
        $manager->getMood('Error occurred');

        $this->assertSame('sad', $manager->getCurrentMood());
    }

    public function test_get_mood_emoji_matches_mood(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Emoji', 'Expressive');

        $manager->getMood('error happened');
        $this->assertSame('😟', $manager->getMoodEmoji());

        $manager->getMood('success!');
        $this->assertSame('🎉', $manager->getMoodEmoji());

        $manager->getMood('analyzing code');
        $this->assertSame('🤔', $manager->getMoodEmoji());

        $manager->getMood('just chatting');
        $this->assertSame('😊', $manager->getMoodEmoji());
    }

    public function test_mood_persists_across_instances(): void
    {
        $manager1 = new BuddyManager();
        $manager1->hatch('Persistent', 'Mood tracker');
        $manager1->getMood('error');

        $manager2 = new BuddyManager();
        $this->assertSame('sad', $manager2->getCurrentMood());
    }

    // ─── Mute / unmute ──────────────────────────────────────────────────

    public function test_companion_is_not_muted_by_default(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Talky', 'Chatty');

        $this->assertFalse($manager->isMuted());
    }

    public function test_mute_makes_companion_muted(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Talky', 'Chatty');
        $manager->mute();

        $this->assertTrue($manager->isMuted());
    }

    public function test_unmute_makes_companion_not_muted(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Talky', 'Chatty');
        $manager->mute();
        $manager->unmute();

        $this->assertFalse($manager->isMuted());
    }

    public function test_mute_persists_across_instances(): void
    {
        $manager1 = new BuddyManager();
        $manager1->hatch('Talky', 'Chatty');
        $manager1->mute();

        $manager2 = new BuddyManager();
        $this->assertTrue($manager2->isMuted());
    }

    public function test_mute_does_nothing_when_not_hatched(): void
    {
        $manager = new BuddyManager();
        $manager->mute(); // Should not throw
        $this->assertFalse($manager->isMuted());
    }

    public function test_companion_intro_returns_null_when_muted(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Silent', 'Quiet');
        $manager->mute();

        $this->assertNull($manager->getCompanionIntroText());
    }

    // ─── Release ─────────────────────────────────────────────────────────

    public function test_release_removes_companion(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Goner', 'About to leave');

        $this->assertTrue($manager->isHatched());
        $manager->release();
        $this->assertFalse($manager->isHatched());
        $this->assertNull($manager->getCompanion());
    }

    public function test_release_removes_json_file(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Goner', 'About to leave');

        $jsonPath = $this->tmpDir . '/.haocode/buddy.json';
        $this->assertFileExists($jsonPath);

        $manager->release();
        $this->assertFileDoesNotExist($jsonPath);
    }

    public function test_release_resets_state(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Goner', 'About to leave');
        $manager->pet();
        $manager->quip('excited');

        $manager->release();

        $this->assertFalse($manager->isPetting());
        $this->assertNull($manager->getQuip());
        $this->assertSame('happy', $manager->getCurrentMood());
    }

    // ─── Rename ──────────────────────────────────────────────────────────

    public function test_rename_changes_name(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('OldName', 'Original');

        $manager->rename('NewName');
        $companion = $manager->getCompanion();

        $this->assertSame('NewName', $companion['name']);
    }

    public function test_rename_persists(): void
    {
        $manager1 = new BuddyManager();
        $manager1->hatch('OldName', 'Original');
        $manager1->rename('NewName');

        $manager2 = new BuddyManager();
        $companion = $manager2->getCompanion();
        $this->assertSame('NewName', $companion['name']);
    }

    public function test_rename_does_nothing_when_not_hatched(): void
    {
        $manager = new BuddyManager();
        $manager->rename('WontWork'); // Should not throw
        $this->assertFalse($manager->isHatched());
    }

    // ─── System prompt injection ─────────────────────────────────────────

    public function test_get_companion_intro_text_returns_string(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Intro', 'Helpful');

        $intro = $manager->getCompanionIntroText();
        $this->assertNotNull($intro);
        $this->assertStringContainsString('Intro', $intro);
        $this->assertStringContainsString($manager->getCompanion()['species'], $intro);
    }

    public function test_get_companion_intro_text_returns_null_when_not_hatched(): void
    {
        $manager = new BuddyManager();
        $this->assertNull($manager->getCompanionIntroText());
    }

    public function test_companion_intro_mentions_separate_watcher(): void
    {
        $manager = new BuddyManager();
        $manager->hatch('Watcher', 'Observes');

        $intro = $manager->getCompanionIntroText();
        $this->assertStringContainsString("You're not Watcher", $intro);
    }
}
