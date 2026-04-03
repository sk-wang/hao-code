<?php

namespace App\Support\Terminal;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Terminal;

class MarkdownRenderer
{
    private readonly MarkdownParser $parser;

    public function __construct(
        private readonly ?int $terminalWidth = null,
    ) {
        $environment = new Environment;
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        $this->parser = new MarkdownParser($environment);
    }

    public function render(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }

        $document = $this->parser->parse($markdown);
        $rendered = $this->renderDocument($document);

        return rtrim($rendered, "\n");
    }

    private function renderDocument(Document $document): string
    {
        $blocks = [];

        foreach ($document->children() as $child) {
            $rendered = $this->renderBlock($child);
            if ($rendered !== '') {
                $blocks[] = $rendered;
            }
        }

        return implode("\n\n", $blocks);
    }

    private function renderBlock(Node $node, int $indent = 0): string
    {
        $rendered = $this->renderBlockRaw($node);

        if ($rendered === '') {
            return '';
        }

        return $indent > 0 ? $this->indentBlock($rendered, $indent) : $rendered;
    }

    private function renderBlockRaw(Node $node): string
    {
        return match (true) {
            $node instanceof Paragraph => $this->renderInlineChildren($node),
            $node instanceof Heading => $this->renderHeading($node),
            $node instanceof FencedCode => $this->renderCodeBlock($node->getLiteral(), $node->getInfoWords()[0] ?? null),
            $node instanceof IndentedCode => $this->renderCodeBlock($node->getLiteral()),
            $node instanceof BlockQuote => $this->renderBlockQuote($node),
            $node instanceof ListBlock => $this->renderList($node),
            $node instanceof ThematicBreak => '<fg=gray>────────────────────</>',
            $node instanceof Table => $this->renderTable($node),
            default => $this->renderContainer($node),
        };
    }

    private function renderContainer(Node $node): string
    {
        $blocks = [];

        foreach ($node->children() as $child) {
            $rendered = $this->renderBlock($child);
            if ($rendered !== '') {
                $blocks[] = $rendered;
            }
        }

        return implode("\n\n", $blocks);
    }

    private function renderHeading(Heading $heading): string
    {
        $text = trim($this->renderInlineChildren($heading));
        if ($text === '') {
            return '';
        }

        $plain = $this->inlinePlainText($heading);
        $underline = str_repeat('─', max(3, min($this->displayWidth($plain), 32)));

        return match ($heading->getLevel()) {
            1 => '<fg=cyan;options=bold>'.$text."</>\n".'<fg=cyan>'.$underline.'</>',
            2 => '<options=bold>'.$text."</>\n".'<fg=gray>'.$underline.'</>',
            default => '<options=bold>'.$text.'</>',
        };
    }

    private function renderCodeBlock(string $code, ?string $language = null): string
    {
        $lines = preg_split("/\r\n|\r|\n/", rtrim($code, "\n")) ?: [''];
        $rendered = [];

        if ($language !== null && $language !== '') {
            $rendered[] = '<fg=gray>'.$this->escape($language).'</>';
        }

        foreach ($lines as $line) {
            $rendered[] = '    <fg=yellow>'.$this->escape($line).'</>';
        }

        return implode("\n", $rendered);
    }

    private function renderBlockQuote(BlockQuote $quote): string
    {
        $content = $this->renderContainer($quote);
        if ($content === '') {
            return '';
        }

        $lines = explode("\n", $content);
        $rendered = [];

        foreach ($lines as $line) {
            $rendered[] = $line === ''
                ? '<fg=gray>│</>'
                : '<fg=gray>│</> '.$line;
        }

        return implode("\n", $rendered);
    }

    private function renderList(ListBlock $list): string
    {
        $lines = [];
        $listData = $list->getListData();
        $number = $listData->start ?? 1;

        foreach ($list->children() as $child) {
            if (! $child instanceof ListItem) {
                continue;
            }

            $marker = $listData->type === ListBlock::TYPE_ORDERED ? $number++.'.' : '•';
            $itemLines = $this->renderListItem($child, $marker);

            if ($itemLines === []) {
                continue;
            }

            if ($lines !== []) {
                $lines[] = '';
            }

            array_push($lines, ...$itemLines);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, string>
     */
    private function renderListItem(ListItem $item, string $marker): array
    {
        $continuationIndent = $this->displayWidth($marker) + 1;
        $lines = [];
        $isFirstBlock = true;

        foreach ($item->children() as $child) {
            $rendered = $this->renderBlock($child);
            if ($rendered === '') {
                continue;
            }

            $blockLines = explode("\n", $rendered);

            if ($isFirstBlock) {
                $firstLine = array_shift($blockLines) ?? '';
                $lines[] = $marker.' '.$firstLine;

                foreach ($blockLines as $line) {
                    $lines[] = str_repeat(' ', $continuationIndent).$line;
                }

                $isFirstBlock = false;

                continue;
            }

            $lines[] = '';

            foreach ($blockLines as $line) {
                $lines[] = str_repeat(' ', $continuationIndent).$line;
            }
        }

        if ($lines === []) {
            $lines[] = $marker;
        }

        return $lines;
    }

    private function renderTable(Table $table): string
    {
        $header = [];
        $rows = [];

        foreach ($table->children() as $section) {
            if (! $section instanceof TableSection) {
                continue;
            }

            foreach ($section->children() as $row) {
                if (! $row instanceof TableRow) {
                    continue;
                }

                $cells = [];
                foreach ($row->children() as $cell) {
                    if (! $cell instanceof TableCell) {
                        continue;
                    }

                    $text = $this->normalizeWhitespace($this->inlinePlainText($cell));
                    $cells[] = [
                        'text' => $text,
                        'align' => $cell->getAlign(),
                        'is_header' => $cell->getType() === TableCell::TYPE_HEADER,
                    ];
                }

                if ($cells !== []) {
                    if ($header === []) {
                        $header[] = $cells;
                    } else {
                        $rows[] = $cells;
                    }
                }
            }
        }

        if ($header === []) {
            return '';
        }

        $columnCounts = [count($header[0] ?? [])];
        foreach ($rows as $row) {
            $columnCounts[] = count($row);
        }

        $columnCount = max($columnCounts);

        $widths = array_fill(0, $columnCount, 3);
        foreach (array_merge($header, $rows) as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index], $this->displayWidth($cell['text']));
            }
        }

        if ($this->tableWidth($widths) > $this->availableTableWidth()) {
            return $this->renderTableFallback($header[0], $rows);
        }

        $lines = [
            $this->tableBorder('┌', '┬', '┐', $widths),
            $this->renderTableRow($header[0], $widths),
        ];

        if ($rows !== []) {
            $lines[] = $this->tableBorder('├', '┼', '┤', $widths);
        }

        foreach ($rows as $index => $row) {
            $lines[] = $this->renderTableRow($row, $widths);

            if ($index < count($rows) - 1) {
                $lines[] = $this->tableBorder('├', '┼', '┤', $widths);
            }
        }

        $lines[] = $this->tableBorder('└', '┴', '┘', $widths);

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array{text: string, align: ?string, is_header: bool}>  $row
     * @param  array<int, int>  $widths
     */
    private function renderTableRow(array $row, array $widths): string
    {
        $cells = [];

        foreach ($widths as $index => $width) {
            $cell = $row[$index] ?? ['text' => '', 'align' => null, 'is_header' => false];
            $text = $this->escape($cell['text']);

            if ($cell['is_header']) {
                $text = '<options=bold>'.$text.'</>';
            }

            $cells[] = ' '.$this->padTableCell($text, $cell['text'], $width, $cell['align']).' ';
        }

        return '│'.implode('│', $cells).'│';
    }

    /**
     * @param  array<int, array{text: string, align: ?string, is_header: bool}>  $header
     * @param  array<int, array<int, array{text: string, align: ?string, is_header: bool}>>  $rows
     */
    private function renderTableFallback(array $header, array $rows): string
    {
        $lines = [];

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex > 0) {
                $lines[] = '';
            }

            $lines[] = '<options=bold>Row '.($rowIndex + 1).'</>';

            foreach ($header as $columnIndex => $heading) {
                $label = $heading['text'] === '' ? 'Column '.($columnIndex + 1) : $heading['text'];
                $value = $row[$columnIndex]['text'] ?? '';
                $lines[] = '  <options=bold>'.$this->escape($label).':</> '.$this->escape($value);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, int>  $widths
     */
    private function tableBorder(string $left, string $middle, string $right, array $widths): string
    {
        $segments = array_map(static fn (int $width): string => str_repeat('─', $width + 2), $widths);

        return $left.implode($middle, $segments).$right;
    }

    private function padTableCell(string $formattedText, string $plainText, int $width, ?string $align): string
    {
        $visibleWidth = $this->displayWidth($plainText);
        $padding = max(0, $width - $visibleWidth);

        return match ($align) {
            TableCell::ALIGN_RIGHT => str_repeat(' ', $padding).$formattedText,
            TableCell::ALIGN_CENTER => str_repeat(' ', intdiv($padding, 2)).$formattedText.str_repeat(' ', $padding - intdiv($padding, 2)),
            default => $formattedText.str_repeat(' ', $padding),
        };
    }

    /**
     * @param  array<int, int>  $widths
     */
    private function tableWidth(array $widths): int
    {
        return array_sum($widths) + count($widths) * 3 + 1;
    }

    private function availableTableWidth(): int
    {
        return max(20, ($this->terminalWidth ?? (new Terminal)->getWidth()) - 2);
    }

    private function renderInlineChildren(Node $node): string
    {
        $rendered = '';

        foreach ($node->children() as $child) {
            $rendered .= $this->renderInlineNode($child);
        }

        return $rendered;
    }

    private function renderInlineNode(Node $node): string
    {
        return match (true) {
            $node instanceof Text => $this->escape($node->getLiteral()),
            $node instanceof Code => '<fg=yellow;options=bold>'.$this->escape($node->getLiteral()).'</>',
            $node instanceof Strong => '<options=bold>'.$this->renderInlineChildren($node).'</>',
            $node instanceof Emphasis => '<options=underscore>'.$this->renderInlineChildren($node).'</>',
            $node instanceof Link => $this->renderLink($node),
            $node instanceof Newline => "\n",
            default => $this->renderInlineChildren($node),
        };
    }

    private function renderLink(Link $link): string
    {
        $label = trim($this->renderInlineChildren($link));
        $plainLabel = trim($this->inlinePlainText($link));
        $url = $link->getUrl();

        if ($label === '') {
            return '<fg=blue;options=underscore>'.$this->escape($url).'</>';
        }

        if ($plainLabel === $url) {
            return '<fg=blue;options=underscore>'.$label.'</>';
        }

        return '<fg=blue;options=underscore>'.$label.'</> <fg=gray>('.$this->escape($url).')</>';
    }

    private function inlinePlainText(Node $node): string
    {
        $text = '';

        foreach ($node->children() as $child) {
            $text .= match (true) {
                $child instanceof Text => $child->getLiteral(),
                $child instanceof Code => $child->getLiteral(),
                $child instanceof Link => $this->renderLinkPlainText($child),
                $child instanceof Newline => "\n",
                default => $this->inlinePlainText($child),
            };
        }

        return $text;
    }

    private function renderLinkPlainText(Link $link): string
    {
        $label = trim($this->inlinePlainText($link));
        $url = $link->getUrl();

        if ($label === '' || $label === $url) {
            return $url;
        }

        return $label.' ('.$url.')';
    }

    private function normalizeWhitespace(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text));

        return $normalized ?? trim($text);
    }

    private function indentBlock(string $text, int $indent): string
    {
        $prefix = str_repeat(' ', $indent);
        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            $lines[$index] = $line === '' ? '' : $prefix.$line;
        }

        return implode("\n", $lines);
    }

    private function escape(string $text): string
    {
        return OutputFormatter::escape($text);
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
}
