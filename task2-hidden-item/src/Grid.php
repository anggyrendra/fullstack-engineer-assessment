<?php

declare(strict_types=1);

namespace Fomotoko\HiddenItem;

/**
 * The fixed grid from the assessment.
 *
 *   ########   row 0
 *   #......#   row 1
 *   #.###..#   row 2
 *   #..#.###   row 3
 *   #X#....#   row 4
 *   ########   row 5
 *
 * Legend:
 *   #  obstacle
 *   .  clear path
 *   X  player's starting position
 *
 * Coordinates are (row, col) with row 0 at the top and col 0 at the left, i.e.
 * "graphics" coordinates. The grid is read-only; the game never mutates it.
 */
final class Grid
{
    /** @var list<string> */
    private array $rows;

    /** @var array{row:int, col:int} */
    private array $start;

    /**
     * @param list<string> $rows one string per row, all the same length
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
        $this->start = $this->locateStart();
    }

    /**
     * The default grid from the assessment.
     */
    public static function default(): self
    {
        return new self([
            '########',
            '#......#',
            '#.###..#',
            '#..#.###',
            '#X#....#',
            '########',
        ]);
    }

    public function rows(): array
    {
        return $this->rows;
    }

    public function height(): int
    {
        return count($this->rows);
    }

    public function width(): int
    {
        return strlen($this->rows[0]);
    }

    /**
     * @return array{row:int, col:int}
     */
    public function start(): array
    {
        return $this->start;
    }

    /**
     * The character at (row, col), or '#' for out-of-bounds (treat the
     * surroundings as a wall).
     */
    public function cell(int $row, int $col): string
    {
        if ($row < 0 || $row >= $this->height() || $col < 0 || $col >= $this->width()) {
            return '#';
        }
        return $this->rows[$row][$col];
    }

    /**
     * True when the cell is passable: a clear path '.' OR the start 'X'
     * (the player stands on the start, so it is passable ground).
     */
    public function isPassable(int $row, int $col): bool
    {
        $cell = $this->cell($row, $col);
        return $cell === '.' || $cell === 'X';
    }

    public function isObstacle(int $row, int $col): bool
    {
        return $this->cell($row, $col) === '#';
    }

    public function isStart(int $row, int $col): bool
    {
        return $this->cell($row, $col) === 'X';
    }

    /**
     * @return array{row:int, col:int}
     */
    private function locateStart(): array
    {
        foreach ($this->rows as $r => $line) {
            $c = strpos($line, 'X');
            if ($c !== false) {
                return ['row' => $r, 'col' => $c];
            }
        }
        throw new \RuntimeException('Grid has no starting position "X".');
    }
}
