---
description: Run tests and fix any failures
argument-hint: optional test filter
allowed-tools: Bash, Read, Edit, Write, Glob, Grep
---

Run the project test suite and fix any failures:

1. Detect the test framework (PHPUnit, Jest, pytest, etc.)
2. Run the tests: `php artisan test` or `vendor/bin/phpunit`$ARGUMENTS
3. If there are failures, read the failing test and source code
4. Fix the issues and re-run tests
5. Report the results

Keep going until all tests pass or you've made 3 attempts per failure.
