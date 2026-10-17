# Repository governance

This document describes the intended governance for the public
`bladecp/bladecp-cpanel` repository. It is a recommendation for the repository
owners to apply. It does not claim that any GitHub setting has already been
enabled.

## Public collaboration

The public GitHub repository keeps a focused, reviewable history of the plugin
and its published releases.

Public issues and pull requests are welcome. Maintainers review them in GitHub
for transparency. An approved contribution follows the complete validation and
release process before it appears in a published release.

Do not expect a merged public pull request to appear in a release until it has
completed that validation and release cycle.

After the first public release, history remains normal and append-only. Do not
rewrite public commits or force-push public branches.

## Recommended GitHub settings

For the default branch, repository owners should consider enabling:

- Pull requests before merge.
- At least one approving review for changes made by contributors.
- Dismissal of stale approvals when new commits are pushed.
- Required successful checks from the public continuous-integration workflow.
- Conversation resolution before merge.
- Blocked force-pushes and branch deletion.

Restrict release creation and version tags to trusted maintainers.

## Issues and pull requests

Use the issue templates for bugs and feature requests. A good report states the
plugin version, the cPanel & WHM version, the operating system, a concise
reproduction path and the observed result.

Never include API keys, passwords, customer records, account names, server
addresses, full logs or screenshots containing sensitive information. Use the
private process in [SECURITY.md](../SECURITY.md) for vulnerabilities.

Pull requests should be focused, explain the user-facing outcome and include
appropriate tests or manual validation notes. Maintainers may request
adjustments before a contribution can be included in a release.

## Releases

Releases are prepared locally from a closed public manifest. They include an
installable archive, SHA-256 checksums, release notes and a reviewed public
source tree. The three-step process is documented in
[the release process](release-process.md).

Preparing a release or creating a local stage never publishes anything. A
separate, explicitly confirmed command is required for an external release.
