# Fullstack Engineer Assessment Test — FOMOTOKO

This repository contains the solutions to the two tasks in the Fullstack
Engineer Assessment Test from PT FOMO INOVASI TEKNOLOGI (FOMOTOKO).

| Task | Folder                  | Language | Summary                                                         |
|------|-------------------------|----------|-----------------------------------------------------------------|
| 1    | `task1-online-store/`   | PHP 8.1+ | A flash-sale store API that handles a race condition safely.    |
| 2    | `task2-hidden-item/`    | PHP 8.1+ | A command-line "hidden item" grid game that finds the item.     |

Both tasks are written in PHP (the language allowed by the assessment), are
clean, commented, and each ships with runnable tests.

## Quick start

Each task is self-contained. From its folder:

```bash
# Task 1 — Online Store API
cd task1-online-store
composer install
composer migrate          # create the SQLite schema (no MySQL needed)
composer seed             # seed a flash-sale product (10 units @ 10.00)
composer serve            # API on http://localhost:8000
composer race-test        # run the race-condition functional test

# Task 2 — Hidden Item
cd task2-hidden-item
composer install
composer run              # print the probable item locations + the grid
composer test             # run the PHPUnit tests
```

See each task's own `README.md` for full details.

## Task 1 — Online Store

A framework-free PHP REST API. The central requirement is handling a race
condition during a flash sale: a burst of concurrent orders that all try to
buy the same heavily-discounted product must never drive the inventory
negative and must sell each unit exactly once.

Race-condition safety comes from:

- a single transaction wrapping "reserve stock + write order";
- row locking (`SELECT ... FOR UPDATE` on MySQL, `BEGIN IMMEDIATE` on
  SQLite);
- an application-level stock check **and** a `WHERE quantity >= :qty` guard
  in the `UPDATE` statement (so a negative quantity is impossible); and
- recording `failed` orders so the race is fully observable.

The functional test (`tests/RaceConditionTest.php`) fires 30 concurrent
buyer processes at a product with 10 units and asserts that exactly 10
succeed, 20 are rejected with HTTP 409, and the inventory ends at 0 (never
negative). It runs from the command line with no external dependencies.

## Task 2 — Hidden Item

Given the 6×8 grid

```
########
#......#
#.###..#
#..#.###
#X#....#
########
```

and the fixed navigation order (Up A → Right B → Down C), the program
enumerates every valid `(A, B, C)` and outputs the 12 probable item
locations. As a bonus it prints the grid with those locations marked with
`$`. It also supports a concrete `--steps A B C` mode that walks an exact
route.

## Requirements

- PHP 8.1 or newer
- PHP extensions: `pdo`, `pdo_sqlite` (for the Task 1 test), `pdo_mysql`
  (only if you deploy Task 1 against MySQL)
- [Composer](https://getcomposer.org/)

## License

MIT.
