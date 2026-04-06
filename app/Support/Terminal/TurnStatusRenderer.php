<?php

namespace App\Support\Terminal;

use Symfony\Component\Console\Output\OutputInterface;

class TurnStatusRenderer
{
    /**
     * A compact subset borrowed from claude-code so the line feels familiar
     * without turning the PHP CLI into a full TUI renderer.
     *
     * A curated subset of Claude Code's spinner verbs so each turn
     * feels a little less repetitive.
     *
     * @var array<int, string>
     */
    private const VERBS = [
        'Accomplishing',
        'Architecting',
        'Calculating',
        'Cerebrating',
        'Cogitating',
        'Considering',
        'Crafting',
        'Crunching',
        'Deciphering',
        'Spelunking',
        'Discombobulating',
        'Finagling',
        'Forging',
        'Imagining',
        'Improvising',
        'Inferring',
        'Manifesting',
        'Perusing',
        'Pondering',
        'Synthesizing',
        'Thinking',
        'Wrangling',
        'Tinkering',
    ];

    /** @var callable(): float */
    private $timeProvider;

    private readonly bool $enabled;

    private string $verb;

    private bool $active = false;

    private bool $paused = false;

    private bool $visible = false;

    private float $startedAt = 0.0;

    private int $lastElapsedSecond = -1;

    private int $approximateChars = 0;

    private ?string $phaseLabel = null;

    /** Seconds after which spinner shows stall warning (red). */
    private const STALL_THRESHOLD_SECONDS = 30;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly ReplFormatter $formatter,
        string $input,
        ?callable $timeProvider = null,
        ?bool $enabled = null,
        ?string $verb = null,
    ) {
        $this->timeProvider = $timeProvider ?? static fn (): float => microtime(true);
        $this->enabled = $enabled ?? ($output->isDecorated() && self::stdoutIsInteractive());
        $this->verb = $verb ?? $this->pickVerb($input);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function start(): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->active = true;
        $this->paused = false;
        $this->startedAt = ($this->timeProvider)();
        $this->lastElapsedSecond = -1;
        $this->render(force: true, currentTime: $this->startedAt);
    }

    public function tick(): void
    {
        if (! $this->enabled || ! $this->active || $this->paused) {
            return;
        }

        $this->render();
    }

    public function pause(): void
    {
        if (! $this->enabled || ! $this->active) {
            return;
        }

        $this->paused = true;
        $this->clearLine();
    }

    public function resume(): void
    {
        if (! $this->enabled || ! $this->active) {
            return;
        }

        $this->paused = false;
        $this->render(force: true);
    }

    public function stop(): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->active = false;
        $this->paused = true;
        $this->clearLine();
        $this->lastElapsedSecond = -1;
        $this->phaseLabel = null;
    }

    public function recordTextDelta(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->approximateChars += $this->displayWidth($text);
    }

    public function setPhaseLabel(?string $label): void
    {
        $this->phaseLabel = $label;

        if (! $this->enabled || ! $this->active || $this->paused) {
            return;
        }

        $this->render(force: true);
    }

    private function render(bool $force = false, ?float $currentTime = null): void
    {
        $now = $currentTime ?? ($this->timeProvider)();
        $elapsedSeconds = max(0, (int) floor($now - $this->startedAt));

        if (! $force && $elapsedSeconds === $this->lastElapsedSecond) {
            return;
        }

        $this->lastElapsedSecond = $elapsedSeconds;
        $isStalled = $elapsedSeconds >= self::STALL_THRESHOLD_SECONDS && $this->approximateChars === 0;
        $approxTokens = $this->approximateChars > 0 ? (int) ceil($this->approximateChars / 4) : null;

        if ($this->phaseLabel !== null) {
            $status = $this->formatter->runningToolStatus($this->phaseLabel, $elapsedSeconds, $isStalled);
        } else {
            $status = $this->formatter->loadingStatus($this->verb, $elapsedSeconds, $approxTokens, $isStalled);
        }

        $this->clearLine();
        $this->writeRaw("\r");
        $this->output->write($status);
        $this->visible = true;
    }

    private function clearLine(): void
    {
        if (! $this->visible) {
            return;
        }

        $this->writeRaw("\r\033[2K");
        $this->visible = false;
    }

    private function pickVerb(string $input): string
    {
        if ($input === '') {
            return self::VERBS[0];
        }

        return self::VERBS[random_int(0, count(self::VERBS) - 1)];
    }

    private function displayWidth(string $text): int
    {
        if (function_exists('mb_strwidth')) {
            return mb_strwidth($text, 'UTF-8');
        }

        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }

        return strlen($text);
    }

    private function writeRaw(string $text): void
    {
        $this->output->write($text, false, OutputInterface::OUTPUT_RAW);
    }

    private static function stdoutIsInteractive(): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }

        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDOUT);
        }

        return false;
    }
}
