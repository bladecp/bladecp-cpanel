# Public release process

This page explains how maintainers prepare and publish BladeCP cPanel
Migrator. The process is intentionally conservative: preparing an artifact
does not publish it.

## The three steps

```sh
./scripts/public-release.sh prepare 0.3.8
./scripts/public-release.sh stage-github 0.3.8 --public-base <clean-public-main-checkout>
./scripts/public-release.sh publish 0.3.8 --confirm-publication v0.3.8
```

`prepare` runs the release gates and creates the reviewed artifact directory.
`stage-github` creates a local Git repository with an independent public
history and the matching version tag. Neither command contacts GitHub or
publishes files. The first public release is staged without
`--public-base`, which starts a new history.

`publish` is intentionally separate. It requires the exact version
confirmation and an additional maintainer-controlled safety lock. It validates
the staged commit, tag, checksums and approved GitHub destination before it
can perform an external write. It never force-pushes.

Versions before 1.0.0 are published as pre-releases.

## What is released

Every public release includes:

- `bladecp-cpanel-migrator-<version>.tar.gz`, the installable archive with
  `install.sh`, `uninstall.sh`, `plugin/`, the READMEs, the changelog and the
  license, inside a `bladecp-cpanel-migrator-<version>/` directory;
- `bladecp-cpanel-migrator-<version>.tar.gz.sha256` and `SHA256SUMS`, so
  users can verify the downloaded archive;
- release notes for that version;
- a curated public source tree with documentation, templates and the public
  checks.

The archive is deterministic: entries are sorted, owned by root, have fixed
modes and carry the release date of the changelog as their time, and the gzip
header has no name or time. Rebuilding the same content gives the same
checksum.

The released archive is validated from the artifact itself on a disposable
cPanel & WHM server: install, connect, uninstall with `--purge` and confirm
that nothing is left behind. Source-tree checks are useful, but they do not
replace this artifact-only validation.

## Safety checks

The preparation process checks version consistency across the plugin, the
changelog and the download links of the READMEs, syntax, automated tests,
the installer and uninstaller on a fake filesystem root, public-tree
structure, links, image metadata, secret patterns and unintended internal
references. It also verifies that the archive is deterministic and matches the
public tree, and produces an audit record.

Automated checks cannot judge every security or editorial concern. The first
public release and material changes receive an independent human review of
code, documentation, images, manifest and release assets. Public images must
carry no metadata.

## History policy

The first public release starts a clean, curated public history. Later
releases add ordinary reviewed commits and version tags to that history. The
private development source remains authoritative: private history is never
mirrored or copied into the public repository, and public history is never
rewritten after the first release.

Public contributions are reviewed in GitHub, then incorporated into the
private development source before returning in a later curated public export.
This keeps the public repository reviewable without creating a second source
of truth.

For the contribution path between the public repository and the primary
development source, see [repository governance](repository-governance.md).
