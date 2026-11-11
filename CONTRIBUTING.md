# Contributing

The Composer scripts run their tools through cpx: `composer global require cpx/cpx`.

```sh
composer install
composer test        # PHPUnit through paratest
composer fix         # code style
composer analyze     # composer validate and Psalm
composer bench       # time and memory per case
composer playground  # every driver side by side
```

CI runs the tests on PHP 8.3–8.6 with GD, Imagick and libvips 8.15, and Psalm and the style check on 8.4.

## Driver tests

`tests/Drivers/DriverTestCase.php` is the conformance suite every driver passes. A driver's test extends it, returns the driver from `::driver()` and states what it can't do by overriding `::hdr()` or constants such as `ICC`. The suite reads the output through GD and Imagick and compares it with `tests/references/`.

## Benchmarks

`composer bench` measures time and peak memory, each iteration in a fresh PHP process: `PlanBench` reads sources and plans thumbs, `DriverBench` makes thumbs of large images that it generates into `bench/images/` on the first run. To compare a change with `main`:

```sh
git switch --detach main && composer bench -- --tag=main && git switch -
composer bench -- --ref=main
```

## Playground

`composer playground` serves and opens <http://127.0.0.1:8080>, with a row per source and case and a column per driver. Δ is how much a thumb differs from the other drivers' thumbs in its row; a large one marks an issue.
