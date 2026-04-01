<?php

namespace App\Tools\Notebook;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class NotebookEditTool extends BaseTool
{
    public function name(): string
    {
        return 'NotebookEdit';
    }

    public function description(): string
    {
        return <<<DESC
Completely replaces the contents of a specific cell in a Jupyter notebook (.ipynb) with new source.

Usage:
- notebook_path: Absolute path to the .ipynb file
- cell_number: 0-indexed cell number
- new_source: The new cell source content
- cell_type: "code" or "markdown" (defaults to current cell type)
- edit_mode: "replace" (default), "insert" to add a new cell, or "delete" to remove

When inserting, the new cell is added AFTER the specified cell_number.
To add at the beginning, use cell_id="" with edit_mode="insert".
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'notebook_path' => [
                    'type' => 'string',
                    'description' => 'Absolute path to the Jupyter notebook file',
                ],
                'cell_number' => [
                    'type' => 'integer',
                    'description' => '0-indexed cell number to edit',
                ],
                'new_source' => [
                    'type' => 'string',
                    'description' => 'The new source content for the cell',
                ],
                'cell_type' => [
                    'type' => 'string',
                    'enum' => ['code', 'markdown'],
                    'description' => 'Cell type (defaults to current)',
                ],
                'edit_mode' => [
                    'type' => 'string',
                    'enum' => ['replace', 'insert', 'delete'],
                    'description' => 'Edit mode: replace, insert, or delete',
                ],
            ],
            'required' => ['notebook_path', 'new_source'],
        ], [
            'notebook_path' => 'required|string',
            'cell_number' => 'nullable|integer|min:0',
            'new_source' => 'required|string',
            'cell_type' => 'nullable|string|in:code,markdown',
            'edit_mode' => 'nullable|string|in:replace,insert,delete',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $path = $input['notebook_path'];

        if (!str_ends_with($path, '.ipynb')) {
            return ToolResult::error("File must be a .ipynb notebook: {$path}");
        }

        if (!file_exists($path)) {
            return ToolResult::error("Notebook not found: {$path}");
        }

        $notebook = json_decode(file_get_contents($path), true);
        if ($notebook === null) {
            return ToolResult::error("Failed to parse notebook JSON: {$path}");
        }

        $cells = &$notebook['cells'];
        $editMode = $input['edit_mode'] ?? 'replace';
        $cellNumber = $input['cell_number'] ?? 0;
        $newSource = $input['new_source'];
        $cellType = $input['cell_type'] ?? null;

        switch ($editMode) {
            case 'delete':
                if (!isset($cells[$cellNumber])) {
                    return ToolResult::error("Cell {$cellNumber} does not exist (notebook has " . count($cells) . " cells)");
                }
                array_splice($cells, $cellNumber, 1);
                break;

            case 'insert':
                $insertAt = $cellNumber + 1;
                $type = $cellType ?? 'code';
                $newCell = [
                    'cell_type' => $type,
                    'metadata' => [],
                    'source' => explode("\n", $newSource),
                ];
                if ($type === 'code') {
                    $newCell['execution_count'] = null;
                    $newCell['outputs'] = [];
                }
                array_splice($cells, $insertAt, 0, [$newCell]);
                break;

            case 'replace':
            default:
                if (!isset($cells[$cellNumber])) {
                    return ToolResult::error("Cell {$cellNumber} does not exist (notebook has " . count($cells) . " cells)");
                }
                $type = $cellType ?? $cells[$cellNumber]['cell_type'] ?? 'code';
                $cells[$cellNumber]['cell_type'] = $type;
                $cells[$cellNumber]['source'] = explode("\n", $newSource);
                if ($type === 'code' && !isset($cells[$cellNumber]['execution_count'])) {
                    $cells[$cellNumber]['execution_count'] = null;
                    $cells[$cellNumber]['outputs'] = [];
                }
                break;
        }

        // Update notebook metadata
        if (isset($notebook['metadata']['language_info'])) {
            // Keep existing language info
        }

        $json = json_encode($notebook, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        file_put_contents($path, $json);

        $action = $editMode;
        $msg = match ($editMode) {
            'delete' => "Deleted cell {$cellNumber}",
            'insert' => "Inserted new cell after cell {$cellNumber}",
            'replace' => "Replaced cell {$cellNumber}",
            default => "Modified cell {$cellNumber}",
        };

        return ToolResult::success("{$msg} in " . basename($path) . " (" . count($cells) . " cells total)");
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function isConcurrencySafe(array $input): bool
    {
        return false;
    }
}
