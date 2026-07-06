Run the project's test suite and analyze results.

Steps:
1. Run `./vendor/bin/phpunit` to execute all tests
2. If tests fail, analyze the failure output
3. For each failure:
   - Identify the failing test class and method
   - Read the relevant test file to understand what it expects
   - Read the source code being tested
   - Explain the root cause
4. Suggest fixes for any failures

If a specific test or filter is mentioned, run only that:
- `./vendor/bin/phpunit --filter=TestMethodName`
- `./vendor/bin/phpunit tests/Unit/SpecificTest.php`

$ARGUMENTS
