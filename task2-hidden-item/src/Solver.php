<?php

declare(strict_types=1);

namespace Fomotoko\HiddenItem;

/**
 * Solves the "hidden item" game.
 *
 * From the starting position the player must navigate in a fixed order:
 *
 *   1. Up/North  A steps
 *   2. Right/East B steps
 *   3. Down/South C steps
 *
 * (A, B, C are non-negative integers.) The player can only step onto a clear
 * path cell or the start cell; obstacles block movement. The item is hidden
 * on one of the clear path points, so we want every cell the player could
 * possibly end up on — and, more generally, every clear cell the player
 * *passes through* on the way — because any of those is a probable hiding
 * spot.
 *
 * Because the exact values of A, B and C are not given by the assessment, the
 * solver enumerates all valid combinations (a is a reachable up-distance, b a
 * reachable right-distance after that, c a reachable down-distance after
 * that). For each combination it records the final position. The set of
 * distinct final positions reachable by *some* valid (A, B, C) is the list of
 * probable item locations.
 *
 * If a concrete (A, B, C) is supplied (see `solveForSteps`), the solver walks
 * that exact route, stopping at the first obstacle, and reports the path it
 * actually took plus its endpoint.
 */
final class Solver
{
    private Grid $grid;

    public function __construct(Grid $grid)
    {
        $this->grid = $grid;
    }

    /**
     * Enumerate every clear-path cell the player can reach by following the
     * Up -> Right -> Down route for *some* valid non-negative (A, B, C).
     *
     * @return array{probable: list<array{row:int,col:int}>, routes: list<array<int,array{row:int,col:int}>>}
     */
    public function solve(): array
    {
        $start = $this->grid->start();

        // Step 1: every position reachable by going Up 0..A steps (stopping on
        // the first obstacle). Includes the start itself (0 steps up).
        $upReachable = $this->reachable($start, -1, 0);  // row-1 each step = Up

        $candidates = [];  // final endpoints after the full route
        $routeIndex = [];  // map "r,c" -> first route that reaches it (for display)

        foreach ($upReachable as $upSteps => $upPos) {
            // Step 2: from the up-position, go Right 0..B steps.
            $rightReachable = $this->reachable($upPos, 0, +1);  // col+1 each step = Right/East

            foreach ($rightReachable as $rightSteps => $rightPos) {
                // Step 3: from the right-position, go Down 0..C steps.
                $downReachable = $this->reachable($rightPos, +1, 0);  // row+1 each step = Down/South

                foreach ($downReachable as $downSteps => $endPos) {
                    $key = $endPos['row'] . ',' . $endPos['col'];
                    if (!isset($candidates[$key])) {
                        // Reconstruct the full route for this endpoint.
                        $candidates[$key] = $endPos;
                        $routeIndex[$key] = $this->buildRoute(
                            $start, (int) $upSteps, $upPos,
                            (int) $rightSteps, $rightPos,
                            (int) $downSteps, $endPos
                        );
                    }
                }
            }
        }

        // Sort by row then col for stable, readable output.
        $probable = array_values($candidates);
        usort($probable, static fn ($a, $b) => $a['row'] <=> $b['row'] ?: $a['col'] <=> $b['col']);

        $routes = array_values($routeIndex);

        return ['probable' => $probable, 'routes' => $routes];
    }

    /**
     * Walk a concrete (A, B, C) route, stopping early on the first obstacle.
     *
     * @return array{path: list<array{row:int,col:int}>, end: array{row:int,col:int}, blocked: bool, blocked_at: ?array{row:int,col:int}}
     */
    public function solveForSteps(int $a, int $b, int $c): array
    {
        $start = $this->grid->start();
        $path  = [$start];
        $pos   = $start;
        $blocked = false;
        $blockedAt = null;

        // Up
        $r = $this->walk($pos, -1, 0, $a, $path);
        $pos = $r['pos'];
        if ($r['blocked']) { $blocked = true; $blockedAt = $r['blocked_at']; }
        // Right
        if (!$blocked) {
            $r = $this->walk($pos, 0, +1, $b, $path);
            $pos = $r['pos'];
            if ($r['blocked']) { $blocked = true; $blockedAt = $r['blocked_at']; }
        }
        // Down
        if (!$blocked) {
            $r = $this->walk($pos, +1, 0, $c, $path);
            $pos = $r['pos'];
            if ($r['blocked']) { $blocked = true; $blockedAt = $r['blocked_at']; }
        }

        return ['path' => $path, 'end' => $pos, 'blocked' => $blocked, 'blocked_at' => $blockedAt];
    }

    // -----------------------------------------------------------------------
    //  Internals
    // -----------------------------------------------------------------------

    /**
     * Walk `steps` cells in direction (dRow, dCol) from `from`, stopping on
     * the first obstacle. Returns the list of positions actually occupied,
     * keyed by how many steps were taken to reach each (0 = start).
     *
     * @param array{row:int,col:int} $from
     * @return array<int, array{row:int,col:int}>
     */
    private function reachable(array $from, int $dRow, int $dCol): array
    {
        $positions = [0 => $from];
        $pos = $from;
        $steps = 0;

        while (true) {
            $next = ['row' => $pos['row'] + $dRow, 'col' => $pos['col'] + $dCol];
            if (!$this->grid->isPassable($next['row'], $next['col'])) {
                break; // obstacle or wall: cannot advance further.
            }
            $pos = $next;
            $steps++;
            $positions[$steps] = $pos;
        }

        return $positions;
    }

    /**
     * Walk up to `steps` cells in direction (dRow, dCol), pushing each visited
     * cell onto $path (excluding the start, which is already there). Stops at
     * the first obstacle.
     *
     * @param array{row:int,col:int} $from
     * @param list<array{row:int,col:int}> $path
     * @return array{pos: array{row:int,col:int}, blocked: bool, blocked_at: ?array{row:int,col:int}}
     */
    private function walk(array $from, int $dRow, int $dCol, int $steps, array &$path): array
    {
        $pos = $from;
        for ($i = 0; $i < $steps; $i++) {
            $next = ['row' => $pos['row'] + $dRow, 'col' => $pos['col'] + $dCol];
            if (!$this->grid->isPassable($next['row'], $next['col'])) {
                return ['pos' => $pos, 'blocked' => true, 'blocked_at' => $next];
            }
            $pos = $next;
            $path[] = $pos;
        }
        return ['pos' => $pos, 'blocked' => false, 'blocked_at' => null];
    }

    /**
     * Reconstruct the ordered list of cells visited for a given (a,b,c).
     *
     * @return list<array{row:int,col:int}>
     */
    private function buildRoute(
        array $start,
        int $upSteps, array $upPos,
        int $rightSteps, array $rightPos,
        int $downSteps, array $endPos
    ): array {
        $route = [$start];
        $this->appendSteps($route, $start, -1, 0, $upSteps);
        $this->appendSteps($route, $upPos, 0, +1, $rightSteps);
        $this->appendSteps($route, $rightPos, +1, 0, $downSteps);
        return $route;
    }

    /**
     * @param list<array{row:int,col:int}> $route
     */
    private function appendSteps(array &$route, array $from, int $dRow, int $dCol, int $steps): void
    {
        $pos = $from;
        for ($i = 0; $i < $steps; $i++) {
            $pos = ['row' => $pos['row'] + $dRow, 'col' => $pos['col'] + $dCol];
            $route[] = $pos;
        }
    }
}
