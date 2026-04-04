<?php

declare(strict_types=1);

namespace App\Services\Buddy;

/**
 * BuddyManager — the full companion pet system.
 *
 * Features:
 *  - Deterministic bone-rolling (species/eye/hat/rarity/shiny/stats)
 *  - Soul persistence (name/personality/hatched_at/mood/muted)
 *  - Idle animation sequence with blink frames
 *  - Speech bubble / quip system with timed fade-out
 *  - Pet hearts animation
 *  - Mute / unmute / release / rename
 *  - System prompt injection for AI awareness
 */
final class BuddyManager
{
    private const BUDDY_FILE = '.haocode/buddy.json';

    /** Idle sequence: mostly rest (frame 0), occasional fidget (frames 1-2), rare blink (-1). */
    public const IDLE_SEQUENCE = [0, 0, 0, 0, 1, 0, 0, 0, -1, 0, 0, 2, 0, 0, 0];

    /** Ticks a quip bubble is visible before fading. */
    public const BUBBLE_SHOW_TICKS = 20;

    /** Ticks for the fade-out window. */
    public const BUBBLE_FADE_TICKS = 6;

    /** Hearts float for ~2.5s (5 ticks at 500ms). */
    public const PET_HEART_BURST_TICKS = 5;

    /** @var string[] Ascending heart animation lines. */
    public const PET_HEARTS = [
        '   ♥    ♥   ',
        '  ♥  ♥   ♥  ',
        ' ♥   ♥  ♥   ',
        '♥  ♥      ♥ ',
        '·    ·   ·  ',
    ];

    /** @var string[] Quip pools keyed by mood. */
    private const QUIPS = [
        'happy' => [
            '*happy quacking noises*',
            '✨ debugging vibes~',
            'watches you type approvingly',
            '*nuzzles the keyboard*',
            'is proud of you',
            'peers at the code with interest',
        ],
        'sad' => [
            '*worried quacking*',
            'hides behind the terminal border',
            'whimpers quietly',
            'offers a sympathetic look',
            '*sad waddle noises*',
        ],
        'excited' => [
            '🎉 YAY!',
            '*excited waddling*',
            'is bouncing with joy!',
            'throws confetti everywhere',
            '*happy dance*',
        ],
        'thinking' => [
            '*strokes chin thoughtfully*',
            'peers at the code...',
            'tilts head curiously',
            '*pondering intensifies*',
            'narrows eyes at the screen',
        ],
        'idle' => [
            '*content humming*',
            'is watching the cursor blink',
            'stretches lazily',
            '*quiet quack*',
            'daydreams about bugs',
        ],
    ];

    private ?array $companion = null;
    private ?array $bones = null;
    private bool $loaded = false;

    /** Current quip state. */
    private ?string $currentQuip = null;
    private int $quipTick = 0;
    private bool $quipFading = false;

    /** Pet animation state. */
    private ?int $petTick = null;

    /** Mood state. */
    private string $mood = 'happy';

    public function __construct()
    {
        $this->load();
    }

    // ─── Core companion access ───────────────────────────────────────────

    public function getCompanion(?string $userId = null): ?array
    {
        if ($this->companion === null) {
            return null;
        }

        $bones = $this->getBones($userId);

        return array_merge($this->companion, $bones);
    }

    public function hatch(string $name, string $personality): array
    {
        $this->companion = [
            'name'        => $name,
            'personality' => $personality,
            'hatched_at'  => date('c'),
            'mood'        => 'happy',
            'muted'       => false,
        ];

        $this->mood = 'happy';
        $this->save();

        return $this->getCompanion() ?? throw new \RuntimeException('Failed to hatch companion.');
    }

    public function isHatched(): bool
    {
        return $this->companion !== null;
    }

    // ─── Sprite rendering ───────────────────────────────────────────────

    /**
     * Get the current sprite lines for the given tick, using idle animation.
     *
     * @return array<int, string>
     */
    public function getFrame(int $tick): array
    {
        $companion = $this->getCompanion();
        if ($companion === null) {
            return [];
        }

        // Map tick through idle sequence
        $seqIndex = $tick % count(self::IDLE_SEQUENCE);
        $seqFrame = self::IDLE_SEQUENCE[$seqIndex];

        if ($seqFrame === -1) {
            // Blink frame
            $lines = CompanionSprites::renderBlink($companion['species'], $companion['eye']);
        } else {
            $lines = CompanionSprites::render($companion['species'], $companion['eye'], $seqFrame);
        }

        $hatLines = CompanionSprites::getHatLines($companion['hat']);
        if ($hatLines !== []) {
            $lines = array_merge($hatLines, $lines);
        }

        return $lines;
    }

    /**
     * Get the compact face representation for narrow terminals.
     */
    public function getFace(): string
    {
        $companion = $this->getCompanion();
        if ($companion === null) {
            return '';
        }

        return CompanionSprites::renderFace($companion['species'], $companion['eye']);
    }

    // ─── Speech bubble / quip system ─────────────────────────────────────

    /**
     * Get the current quip text, or null if no quip is active.
     * Returns null if muted.
     */
    public function getQuip(): ?string
    {
        if ($this->companion === null || ($this->companion['muted'] ?? false)) {
            return null;
        }

        if ($this->currentQuip === null) {
            return null;
        }

        return $this->currentQuip;
    }

    /**
     * Whether the quip is in the fade-out window.
     */
    public function isQuipFading(): bool
    {
        return $this->quipFading;
    }

    /**
     * Advance the quip timer by one tick. Call this once per 500ms.
     */
    public function tickQuip(): void
    {
        if ($this->currentQuip === null) {
            return;
        }

        $this->quipTick++;

        if ($this->quipTick >= self::BUBBLE_SHOW_TICKS + self::BUBBLE_FADE_TICKS) {
            // Quip expired
            $this->currentQuip = null;
            $this->quipTick = 0;
            $this->quipFading = false;
            return;
        }

        $this->quipFading = $this->quipTick >= self::BUBBLE_SHOW_TICKS;
    }

    /**
     * Trigger a random quip for the given mood.
     */
    public function quip(string $mood = 'happy'): void
    {
        if ($this->companion === null || ($this->companion['muted'] ?? false)) {
            return;
        }

        $pool = self::QUIPS[$mood] ?? self::QUIPS['happy'];
        $name = $this->companion['name'];

        // Pick a random quip and prepend the name
        $quip = $pool[array_rand($pool)];
        $this->currentQuip = "{$name} {$quip}";
        $this->quipTick = 0;
        $this->quipFading = false;
    }

    /**
     * Clear the current quip immediately.
     */
    public function clearQuip(): void
    {
        $this->currentQuip = null;
        $this->quipTick = 0;
        $this->quipFading = false;
    }

    // ─── Pet animation ──────────────────────────────────────────────────

    /**
     * Trigger the pet hearts animation.
     */
    public function pet(): void
    {
        $this->petTick = 0;
        $this->quip('excited');
    }

    /**
     * Get the current hearts frame, or null if not petting.
     */
    public function getPetHearts(): ?string
    {
        if ($this->petTick === null) {
            return null;
        }

        $frame = $this->petTick;
        $this->petTick++;

        if ($this->petTick >= self::PET_HEART_BURST_TICKS) {
            $this->petTick = null;
        }

        return self::PET_HEARTS[$frame] ?? null;
    }

    /**
     * Whether the pet animation is currently active.
     */
    public function isPetting(): bool
    {
        return $this->petTick !== null;
    }

    // ─── Mood system ────────────────────────────────────────────────────

    public function getMood(?string $lastToolResult = null): string
    {
        if ($lastToolResult === null) {
            return $this->mood;
        }

        $lower = strtolower($lastToolResult);

        if (str_contains($lower, 'error') || str_contains($lower, 'fail')) {
            $this->mood = 'sad';
        } elseif (str_contains($lower, 'success') || str_contains($lower, 'done') || str_contains($lower, 'complete')) {
            $this->mood = 'excited';
        } elseif (str_contains($lower, 'thinking') || str_contains($lower, 'analyz')) {
            $this->mood = 'thinking';
        } else {
            $this->mood = 'happy';
        }

        // Persist mood
        if ($this->companion !== null) {
            $this->companion['mood'] = $this->mood;
            $this->save();
        }

        return $this->mood;
    }

    public function getCurrentMood(): string
    {
        return $this->mood;
    }

    public function getMoodEmoji(): string
    {
        return match ($this->mood) {
            'sad'      => '😟',
            'excited'  => '🎉',
            'thinking' => '🤔',
            'idle'     => '😴',
            default    => '😊',
        };
    }

    // ─── Mute / unmute ──────────────────────────────────────────────────

    public function isMuted(): bool
    {
        return ($this->companion['muted'] ?? false) === true;
    }

    public function mute(): void
    {
        if ($this->companion === null) {
            return;
        }
        $this->companion['muted'] = true;
        $this->save();
    }

    public function unmute(): void
    {
        if ($this->companion === null) {
            return;
        }
        $this->companion['muted'] = false;
        $this->save();
    }

    // ─── Release / rename ───────────────────────────────────────────────

    public function release(): void
    {
        $this->companion = null;
        $this->bones = null;
        $this->currentQuip = null;
        $this->quipTick = 0;
        $this->mood = 'happy';
        $this->petTick = null;

        $path = $this->getFilePath();
        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function rename(string $newName): void
    {
        if ($this->companion === null) {
            return;
        }
        $this->companion['name'] = $newName;
        $this->save();
    }

    // ─── Card display ───────────────────────────────────────────────────

    /**
     * @return array<int, string>
     */
    public function getCard(): array
    {
        $companion = $this->getCompanion();
        if ($companion === null) {
            return ['<fg=gray>No companion hatched yet. Use /buddy hatch <name></>'];
        }

        $rarity = $companion['rarity'];
        $stars = CompanionTypes::RARITY_STARS[$rarity];
        $color = CompanionTypes::RARITY_COLORS[$rarity];
        $species = $companion['species'];
        $name = $companion['name'];
        $personality = $companion['personality'];
        $shiny = $companion['shiny'] ? ' <fg=yellow>✨ SHINY</>' : '';
        $moodEmoji = $this->getMoodEmoji();

        $lines = [];
        $lines[] = "<fg={$color}>{$stars}</> <fg=white>{$species}</>{$shiny} {$moodEmoji}";
        $lines[] = "<fg=cyan;bold>{$name}</>";
        $lines[] = "<fg=gray>{$personality}</>";
        $lines[] = '';

        // Stats as progress bars
        foreach ($companion['stats'] as $statName => $value) {
            $barWidth = 20;
            $filled = (int) round(($value / 100) * $barWidth);
            $filled = min($filled, $barWidth);
            $bar = str_repeat('█', $filled) . str_repeat('░', $barWidth - $filled);
            $lines[] = sprintf('  <fg=gray>%-10s</> <fg=cyan>%s</> <fg=white>%3d</>', $statName, $bar, $value);
        }

        // Sprite
        $lines[] = '';
        foreach ($this->getFrame(0) as $spriteLine) {
            $lines[] = "  <fg=white>{$spriteLine}</>";
        }

        return $lines;
    }

    /**
     * Get a narrow-mode one-line display for the companion.
     */
    public function getNarrowLine(): string
    {
        $companion = $this->getCompanion();
        if ($companion === null) {
            return '';
        }

        $face = $this->getFace();
        $name = $companion['name'];

        if ($this->currentQuip !== null) {
            $quip = mb_substr($this->currentQuip, 0, 24);
            return "<fg=white>{$face}</> <fg=gray>{$quip}</>";
        }

        return "<fg=white>{$face}</> <fg=cyan>{$name}</>";
    }

    // ─── System prompt injection ────────────────────────────────────────

    /**
     * Generate a companion intro text for the AI system prompt.
     * Tells the AI about the companion so it knows not to narrate what the companion says.
     */
    public function getCompanionIntroText(): ?string
    {
        $companion = $this->getCompanion();
        if ($companion === null || ($companion['muted'] ?? false)) {
            return null;
        }

        $name = $companion['name'];
        $species = $companion['species'];

        return "A small {$species} named {$name} sits beside the user's input box and occasionally comments in a speech bubble. You're not {$name} — it's a separate watcher. When the user addresses {$name} by name, respond briefly and don't narrate what {$name} might say.";
    }

    // ─── Save / load ────────────────────────────────────────────────────

    public function save(): void
    {
        if ($this->companion === null) {
            return;
        }

        $dir = dirname($this->getFilePath());
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->getFilePath(),
            json_encode($this->companion, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $path = $this->getFilePath();
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if (is_array($data) && isset($data['name'])) {
                $this->companion = $data;
                $this->mood = $data['mood'] ?? 'happy';
            }
        }

        $this->loaded = true;
    }

    private function getBones(?string $userId): array
    {
        if ($this->bones !== null) {
            return $this->bones;
        }

        $id = $userId ?? $this->companion['name'] ?? 'default';
        $this->bones = CompanionRoller::roll($id);

        return $this->bones;
    }

    private function getFilePath(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        return $home . '/' . self::BUDDY_FILE;
    }
}
