#!/usr/bin/env php
<?php

/**
 * Task 2: Hidden Item — command-line program.
 *
 * Given the grid from the assessment and the fixed navigation order
 * (Up A, then Right B, then Down C), this program outputs the list of
 * probable coordinate points where the hidden item might be located, and —
 * as a bonus — prints the grid with those probable locations marked with `$`.
 *
 * Usage:
 *   php hidden_item.php                       # solve for all valid (A,B,C)
 *   php hidden_item.php --steps A B C         # solve for a concrete A,B,C
 *   php hidden_item.php --no-grid             # omit the bonus grid display
 *
 * Coordinates are printed as (row, col) with (0,0) at the top-left.
 */

declare(strict_types=1);

use Fomotoko\HiddenItem\Grid;
use Fomotoko\HiddenItem\Renderer;
use Fomotoko\HiddenItem\Solver;

require __DIR__ . '/../vendor/autoload.php';

// ---- Parse arguments -------------------------------------------------------
$showGrid  = true;
$concrete  = null;   // [a, b, c] when --steps is given

$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if ($arg === '--no-grid') {
        $showGrid = false;
    } elseif ($arg === '--steps') {
        $concrete = [(int) ($args[$i + 1] ?? 0), (int) ($args[$i + 2] ?? 0), (int) ($args[$i + 3] ?? 0)];
        $i += 3;
    } elseif ($arg === '-h' || $arg === '--help') {
        echo file_get_contents(__DIR__ . '/../HELP.txt') ?: '';
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        fwrite(STDERR, "Run 'php hidden_item.php --help' for usage.\n");
        exit(2);
    }
}

// ---- Solve -----------------------------------------------------------------
$grid   = Grid::default();
$solver = new Solver($grid);

echo "\n";
echo "HIDDEN ITEM\n";
echo "-----------\n";
echo "Grid ({$grid->width()}x{$grid->height()}), start at ({$grid->start()['row']}, {$grid->start()['col']}).\n";
echo "Navigation order: Up A, then Right B, then Down C.\n\n";

if ($concrete !== null) {
    [$a, $b, $c] = $concrete;
    echo "Solving for concrete steps A={$a}, B={$b}, C={$c}:\n\n";
    $result = $solver->solveForSteps($a, $b, $c);

    echo "Path taken (in order):\n";
    foreach ($result['path'] as $i => $cell) {
        echo "  step {$i}: ({$cell['row']}, {$cell['col']})\n";
    }
    echo "\nFinal position: ({$result['end']['row']}, {$result['end']['col']})\n";

    if ($result['blocked']) {
        $ba = $result['blocked_at'];
        echo "Route was blocked by an obstacle at ({$ba['row']}, {$ba['col']}).\n";
    }

    // For the concrete case the probable item location is the endpoint, but
    // we also report it as the single probable point.
    $probable = [$result['end']];
} else {
    $result = $solver->solve();
    $probable = $result['probable'];

    echo "Probable item locations (reachable by some valid Up->Right->Down route):\n";
    echo "Total: " . count($probable) . " probable point(s)\n\n";

    echo "Coordinates (row, col):\n";
    foreach ($probable as $i => $cell) {
        printf("  %2d. (%d, %d)\n", $i + 1, $cell['row'], $cell['col']);
    }
    echo "\n";

    // Also show a few example routes so the reasoning is transparent.
    echo "Example routes (A, B, C) that reach a probable point:\n";
    $shown = 0;
    foreach ($result['routes'] as $route) {
        if ($shown >= 5) {
            echo "  ... and " . (count($result['routes']) - $shown) . " more.\n";
            break;
        }
        $cells = [];
        foreach ($route as $cell) {
            $cells[] = "({$cell['row']},{$cell['col']})";
        }
        echo '  ' . implode(' -> ', $cells) . "\n";
        $shown++;
    }
    echo "\n";
}

// ---- Bonus: grid with probable locations marked with '$' -------------------
if ($showGrid) {
    echo "Grid with probable item locations marked as '$' (bonus):\n\n";
    echo (new Renderer($grid))->render($probable);
}

echo "Legend:  # = obstacle   . = clear path   X = start   \$ = probable item location\n\n";
