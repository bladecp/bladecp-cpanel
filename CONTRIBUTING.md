# Contributing to BladeCP cPanel Migrator

Thanks for taking the time to contribute. This repository contains the
official BladeCP plugin for cPanel & WHM: the WHM plugin that migrates cPanel
accounts to BladeCP and the cPanel app it adds for each account.

## How public contributions are handled

GitHub issues and pull requests are reviewed publicly. An approved change
passes the complete project validation and release review before it appears in
a published release.

This means a merged pull request may not appear in a public release
immediately. After the first public release, public commits are added
normally and are never rewritten or force-pushed.

Read [repository governance](docs/repository-governance.md) for the complete
policy.

## Scope

Useful contributions include bug fixes, reliability improvements, accessibility
and translation fixes, documentation improvements, and focused enhancements to
the supported cPanel & WHM integration.

Before starting a large feature, open an issue to describe the problem and the
proposed outcome. This helps us confirm scope and avoid duplicate work.

Do not include customer data, API keys, passwords, account names, server
addresses or other sensitive information in issues, pull requests, commits,
screenshots or test fixtures.

## Development environment

- PHP 8.1 or later, with no extension beyond curl and json (`posix` is
  optional). No Composer installation or build step is required.
- Perl 5 for the checks of the cPanel API module and the AdminBin.
- A disposable cPanel & WHM server for any change that touches the installer,
  WHM, cPanel, `pkgacct` or the cron worker.

Keep changes focused and preserve compatibility with PHP 8.1. Follow the
existing style in the files you modify, including strict types and defensive
error handling. Every user-facing text lives in `plugin/lang/` and must be
added to the English, Portuguese (Brazil) and Spanish catalogues in the same
change.

## Validation

Run the public checks from the repository root before opening a pull request:

```sh
find plugin tests -type f \( -name '*.php' -o -name '*.cgi' \) -print0 | xargs -0 -n1 php -l
php tests/run.php
bash tests/perl/run.sh
bash tests/install/run.sh
bash scripts/check-public-tree.sh
```

The install check uses a temporary fake filesystem root and never touches the
machine it runs on. Changes that affect WHM or cPanel behavior should also be
exercised on a non-production server. Describe the cPanel & WHM version, the
operating system and the scenarios you tested in the pull request.
Maintainers run the complete automated, security and release gates before
accepting or publishing a release. A green public check does not replace the
final artifact validation performed before a release.

## Pull requests

1. Start from the current default branch and create a focused branch.
2. Describe the user-facing problem, the chosen solution, and the validation
   you performed.
3. Add or update tests when behavior changes.
4. Keep unrelated formatting or refactoring out of the pull request.
5. Be ready to address review feedback before merge.

Please write commit messages and pull-request descriptions clearly enough that
reviewers can understand the intended behavior from the public context.

## Security reports

Do not use GitHub issues or pull requests to report a vulnerability. Follow
the private reporting process in [SECURITY.md](SECURITY.md).

## License

By contributing, you agree that your contributions are licensed under the
project's [MIT License](LICENSE).
