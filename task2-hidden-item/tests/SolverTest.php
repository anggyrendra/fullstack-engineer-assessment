<?php

/**
 * Tests for the Hidden Item solver.
 *
 * The default grid from the assessment is:
 *
 *   ########   row 0
 *   #......#   row 1
 *   #.###..#   row 2
 *   #..#.###   row 3
 *   #X#....#   row 4   <- start at (4, 1)
 *   ########   row 5
 */

declare(strict_types=1);

namespace Fomotoko\HiddenItem\Tests;

use Fomotoko\HiddenItem\Grid;
use Fomotoko\HiddenItem\Renderer;
use Fomotoko\HiddenItem\Solver;
use PHPUnit\Framework\TestCase;

final class SolverTest extends TestCase
{
    public function testStartIsLocatedAtRow4Col1(): void
    {
        $grid = Grid::default();
        self::assertSame(['row' => 4, 'col' => 1], $grid->start());
    }

    public function testObstaclesAreNotPassable(): void
    {
        $grid = Grid::default();
        // Corners and the wall at (4,2) are obstacles.
        self::assertTrue($grid->isObstacle(0, 0));
        self::assertTrue($grid->isObstacle(4, 2));
        self::assertFalse($grid->isPassable(4, 2));
        // Clear paths and the start are passable.
        self::assertTrue($grid->isPassable(1, 1));
        self::assertTrue($grid->isPassable(4, 1)); // start is passable
    }

    public function testSolverReturnsProbablePointsOnlyOnClearPaths(): void
    {
        $solver   = new Solver(Grid::default());
        $probable = $solver->solve()['probable'];

        self::assertNotEmpty($probable, 'There should be at least one probable location.');

        // Every probable point must be a passable cell (clear path or start).
        $grid = Grid::default();
        foreach ($probable as $p) {
            self::assertTrue(
                $grid->isPassable($p['row'], $p['col']),
                "Probable point ({$p['row']}, {$p['col']}) must be passable."
            );
        }
    }

    public function testStartItselfIsAProbableLocation(): void
    {
        // With A=B=C=0 the player never moves, so the start is reachable.
        $probable = (new Solver(Grid::default()))->solve()['probable'];
        $hasStart = false;
        foreach ($probable as $p) {
            if ($p['row'] === 4 && $p['col'] === 1) {
                $hasStart = true;
                break;
            }
        }
        self::assertTrue($hasStart, 'The start cell (4,1) must be a probable location.');
    }

    public function testConcreteRouteUp3Right4Down0ReachesCell(): void
    {
        // From (4,1): up 3 -> (1,1); right 4 -> (1,5); down 0 -> (1,5).
        $result = (new Solver(Grid::default()))->solveForSteps(3, 4, 0);

        self::assertSame(['row' => 1, 'col' => 5], $result['end']);
        self::assertFalse($result['blocked']);
        // Path: start -> up3 -> right4
        self::assertSame(['row' => 4, 'col' => 1], $result['path'][0]);
        self::assertSame(['row' => 1, 'col' => 1], $result['path'][3]);
        self::assertSame(['row' => 1, 'col' => 5], $result['path'][7]);
    }

    public function testConcreteRouteStopsAtObstacle(): void
    {
        // From (4,1): up 1 -> (3,1); right 1 -> (3,2); right again is blocked? (3,2)=. ok;
        // The cell (4,2) is '#'. Going down 1 from (3,1) would hit (4,1)=X ok, but
        // we test a route that is blocked: from (4,1) right 1 -> (4,2) which is '#'.
        $result = (new Solver(Grid::default()))->solveForSteps(0, 1, 0);

        self::assertTrue($result['blocked']);
        self::assertSame(['row' => 4, 'col' => 2], $result['blocked_at']);
        // Player stayed at the start.
        self::assertSame(['row' => 4, 'col' => 1], $result['end']);
    }

    public function testRendererMarksProbableLocationsWithDollar(): void
    {
        $grid   = Grid::default();
        $probable = (new Solver($grid))->solve()['probable'];

        $rendered = (new Renderer($grid))->render($probable);

        self::assertStringContainsString('$', $rendered, 'Grid should contain $ for probable locations.');
        self::assertStringContainsString('X', $rendered, 'Start X must still be visible.');
        self::assertStringContainsString('#', $rendered, 'Obstacles must still be visible.');
    }
}
