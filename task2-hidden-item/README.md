# Task 2 — Hidden Item (command-line game)

A small command-line program that solves the "hidden item" grid game from the
assessment.

## The game

The grid (6 rows × 8 columns):

```
########
#......#
#.###..#
#..#.###
#X#....#
########
```

Legend:

- `#` — obstacle
- `.` — clear path
- `X` — the player's starting position (row 4, col 1)

An item is hidden on one of the clear-path points. From the starting position
the player must navigate in a fixed order:

1. **Up / North** — A steps
2. **Right / East** — B steps
3. **Down / South** — C steps

The program outputs the list of **probable coordinate points** where the item
might be located, and — as a bonus — prints the grid with those probable
locations marked with a `$` symbol.

## How it works

Because the assessment does not specify concrete values for A, B and C, the
solver enumerates **every valid combination**: for each position reachable by
going Up some number of steps (stopping at the first obstacle), it then
explores every position reachable by going Right, and from each of those
every position reachable by going Down. The set of distinct endpoints is the
list of probable item locations.

A concrete `(A, B, C)` can also be supplied with `--steps A B C`; in that mode
the solver walks exactly that route, stopping early at the first obstacle, and
reports the full path and the final position.

Coordinates are `(row, col)` with `(0, 0)` at the top-left of the grid.

## Usage

```bash
composer install
composer run           # run the program (alias for: php bin/hidden_item.php)
```

### Examples

Solve for all valid `(A, B, C)` (the default):

```bash
php bin/hidden_item.php
```

Output:

```
HIDDEN ITEM
-----------
Grid (8x6), start at (4, 1).
Navigation order: Up A, then Right B, then Down C.

Probable item locations (reachable by some valid Up->Right->Down route):
Total: 12 probable point(s)

Coordinates (row, col):
   1. (1, 1)
   2. (1, 2)
   3. (1, 3)
   4. (1, 4)
   5. (1, 5)
   6. (1, 6)
   7. (2, 1)
   8. (2, 5)
   9. (2, 6)
  10. (3, 1)
  11. (3, 2)
  12. (4, 1)

Grid with probable item locations marked as '$' (bonus):

########
#$$$$$$#
#$###$$#
#$$#.###
#X#....#
########
Legend:  # = obstacle   . = clear path   X = start   $ = probable item location
```

Solve for a concrete route (A=3, B=4, C=0):

```bash
php bin/hidden_item.php --steps 3 4 0
```

This walks `(4,1) → up 3 → (1,1) → right 4 → (1,5)`, so the item is at `(1, 5)`.

Hide the bonus grid:

```bash
php bin/hidden_item.php --no-grid
```

## Why some clear-path cells are NOT probable

There are 17 passable cells on the grid, but only 12 are reachable via the
Up → Right → Down order. The unreachable passable cells are
`(3,4), (4,3), (4,4), (4,5), (4,6)`. They sit behind the wall at `(4,2)` and
the internal walls at `(3,3)` / `(3,5)`, which the fixed navigation order
cannot get around (you would need to go right *before* down, which the order
forbids). The solver correctly excludes them.

## Tests

```bash
composer test        # PHPUnit, 7 tests
```

The tests cover: start position, obstacle/passable detection, the probable
set only containing passable cells, the start being a probable location
(the A=B=C=0 case), a concrete route reaching the expected cell, a route that
is blocked by an obstacle, and the renderer marking locations with `$`.

## Project layout

```
task2-hidden-item/
├── composer.json
├── phpunit.xml
├── HELP.txt
├── bin/
│   └── hidden_item.php        # CLI entry point
├── src/
│   ├── Grid.php               # the fixed grid + passability helpers
│   ├── Solver.php             # Up->Right->Down enumeration + concrete walker
│   └── Renderer.php           # prints the grid with $ markers (bonus)
└── tests/
    └── SolverTest.php
```
