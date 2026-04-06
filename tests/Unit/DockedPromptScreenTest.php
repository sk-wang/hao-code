<?php

namespace Tests\Unit;

use App\Support\Terminal\DockedPromptScreen;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class DockedPromptScreenTest extends TestCase
{
    public function test_it_docks_hud_lines_at_the_bottom_of_the_terminal(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $screen = new DockedPromptScreen($output, static fn (): int => 10);

        $screen->render(
            suggestionLines: ['suggestion one', 'suggestion two'],
            promptLine: 'prompt line',
            cursorColumn: 3,
            hudLines: ['hud line 1', 'hud line 2'],
        );

        $display = $output->fetch();

        $this->assertStringContainsString("\033[8;4H", $display);
        $this->assertStringNotContainsString("\033[H\033[2J", $display);
        $this->assertLessThan(strpos($display, 'prompt line'), strpos($display, 'suggestion two'));
        $this->assertLessThan(strpos($display, 'hud line 1'), strpos($display, 'prompt line'));
        $this->assertLessThan(strpos($display, 'hud line 2'), strpos($display, 'hud line 1'));
    }

    public function test_it_only_updates_the_prompt_line_when_the_layout_is_stable(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $screen = new DockedPromptScreen($output, static fn (): int => 10);

        $screen->render(
            suggestionLines: [],
            promptLine: 'prompt line a',
            cursorColumn: 1,
            hudLines: ['hud line 1', 'hud line 2'],
        );
        $output->fetch();

        $screen->render(
            suggestionLines: [],
            promptLine: 'prompt line ab',
            cursorColumn: 2,
            hudLines: ['hud line 1', 'hud line 2'],
        );

        $display = $output->fetch();

        $this->assertStringNotContainsString("\033[J", $display);
        $this->assertStringContainsString("\033[8;1H\033[2K", $display);
        $this->assertStringContainsString('prompt line ab', $display);
        $this->assertStringNotContainsString('hud line 1', $display);
        $this->assertStringNotContainsString('hud line 2', $display);
    }

    public function test_it_clears_removed_lines_when_the_block_shrinks(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $screen = new DockedPromptScreen($output, static fn (): int => 10);

        $screen->render(
            suggestionLines: ['one', 'two', 'three'],
            promptLine: 'prompt line',
            cursorColumn: 0,
            hudLines: ['hud line 1', 'hud line 2'],
        );
        $output->fetch();

        $screen->render(
            suggestionLines: [],
            promptLine: 'prompt line',
            cursorColumn: 0,
            hudLines: ['hud line 1', 'hud line 2'],
        );

        $display = $output->fetch();

        $this->assertStringContainsString("\033[5;1H\033[2K", $display);
        $this->assertStringContainsString("\033[6;1H\033[2K", $display);
        $this->assertStringContainsString("\033[7;1H\033[2K", $display);
        $this->assertStringNotContainsString('prompt line', $display);
        $this->assertStringNotContainsString('hud line 2', $display);
    }

    public function test_clear_removes_the_reserved_block_using_the_last_reserved_height(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $screen = new DockedPromptScreen($output, static fn (): int => 10);

        $screen->render(
            suggestionLines: ['one'],
            promptLine: 'prompt line',
            cursorColumn: 0,
            hudLines: ['hud line 1', 'hud line 2'],
        );
        $output->fetch();

        $screen->clear();

        $display = $output->fetch();

        $this->assertStringContainsString("\033[7;1H\033[2K", $display);
        $this->assertStringContainsString("\033[8;1H\033[2K", $display);
        $this->assertStringContainsString("\033[9;1H\033[2K", $display);
        $this->assertStringContainsString("\033[10;1H\033[2K", $display);
    }
}
