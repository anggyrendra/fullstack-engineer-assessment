<?php

declare(strict_types=1);

namespace Fomotoko\HiddenItem;

/**
 * Renders the grid as text, optionally marking probable item locations with
 * the `$` symbol (the bonus requirement).
 *
 * The start cell is rendered as `X`, obstacles as `#`, clear paths as `.`,
 * and any probable item location as `$`. The probable set is provided by the
 * Solver.
 */
final class Renderer
{
    private Grid $grid;

    public function __construct(Grid $grid)
    {
        $this->grid = $grid;
    }

    /**
     * @param list<array{row:int,col:int}> $probable
     */
    public function render(array $probable = []): string
    {
        // Index probable cells by "row,col" for O(1) lookup.
        $marked = [];
        foreach ($probable as $p) {
            $marked[$p['row'] . ',' . $p['col']] = true;
        }

        $out = '';
        for ($r = 0; $r < $this->grid->height(); $r++) {
            for ($c = 0; $c < $this->grid->width(); $c++) {
                $cell = $this->grid->cell($r, $c);
                if ($cell === 'X') {
                    $out .= 'X';
                } elseif (isset($marked[$r . ',' . $c])) {
                    $out .= '$';
                } else {
                    $out .= $cell;
                }
            }
            $out .= "\n";
        }

        return $out;
    }
}
