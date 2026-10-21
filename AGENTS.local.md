# Personal Test Execution Policy

> Local-only policy for this checkout. It is intentionally untracked and must
> not be committed, pushed, or treated as a shared project convention.

## Incremental Testing Rules

1. After each small, logically independent code change, run only the most relevant targeted test or test group.
2. Before selecting tests, inspect the changed code and identify the affected behavior, related tests, and likely regression risks.
3. Prefer the narrowest meaningful PHPUnit command, for example:

   `php artisan test tests/Feature/BookingPackageNameSnapshotTest.php`

   Or, for a single test:

   `php artisan test tests/Feature/BookingPackageNameSnapshotTest.php --filter=test_store_snapshots_package_name`

4. Do not run the full test suite after every small change.
5. After completing a logically related group of changes, run all relevant tests for the affected feature/module.
6. Run broader regression tests when changes affect shared services, financial calculations, booking write paths, authorization, database behavior, or multiple modules.
7. Run the full test suite when:
   - I explicitly request it.
   - A major feature or cross-module change is completed and broader regression testing is justified.
   - The change has substantial system-wide risk.
   - Targeted tests reveal failures that require broader investigation.
   - A release or final verification explicitly requires it.
8. Do not automatically repeat the entire test suite after fixing an unrelated or isolated failure. Rerun the failed test and the relevant regression tests first.
9. Never skip a test that is necessary to verify the changed behavior merely to save time.
10. Run `vendor/bin/pint` on the relevant changed PHP files where practical. Do not format the entire repository unnecessarily.

## Test Selection and Evidence

For every implementation task:

- Identify the tests to run and briefly explain why they are relevant.
- Execute the selected tests; do not merely recommend commands.
- Report the exact commands executed and their actual results.
- Distinguish PASS, FAIL, BLOCKED, and NOT RUN.
- Never claim the full suite passed if only targeted tests were executed.
- If a relevant test does not exist, consider adding a focused regression test.
- If a test fails, investigate whether the failure is caused by the change, an existing defect, or the environment.
- Do not weaken or delete assertions merely to make tests pass.
- Do not modify production code solely to satisfy an incorrect test expectation without verifying the intended business rule.

## Personal-Only Restrictions

- This policy belongs only to me.
- Do not add it to `AGENTS.md`, `.gitignore`, shared CI configuration, `composer.json`, or any tracked project configuration.
- Add `AGENTS.local.md` to `.git/info/exclude`, preserving all existing entries.
- Verify that the policy file is ignored by Git and does not appear as an untracked file.
- Do not stage, commit, or push this policy.
- Do not change how other developers run tests.
- If the current agent cannot automatically load `AGENTS.local.md`, explain the supported way to activate it and do not silently claim the policy is active.
