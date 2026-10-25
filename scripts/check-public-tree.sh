#!/usr/bin/env bash
# Standalone public-package hygiene and structure gate.
#
#   bash scripts/check-public-tree.sh
#
# Runs from the root of the public repository. It needs bash, find, grep, php
# and python3.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

for command in find grep php python3; do
  command -v "$command" >/dev/null 2>&1 || {
    echo "error: $command is required" >&2
    exit 2
  }
done

required=(
  LICENSE README.md README.pt-BR.md README.es.md CHANGELOG.md SECURITY.md CONTRIBUTING.md
  install.sh uninstall.sh
  plugin/VERSION plugin/src/bootstrap.php plugin/cgi/index.cgi plugin/bin/worker.php plugin/bin/client-admin.php
  plugin/appconfig/bladecp_migrator.conf plugin/lang/en.php plugin/lang/pt-BR.php plugin/lang/es.php
  plugin/assets/app.css plugin/assets/bladecp_migrator.png plugin/assets/fonts/LICENSE.txt
  plugin/cpanel/plugin/install.json plugin/cpanel/plugin/site_migration.svg plugin/cpanel/plugin/menu/LeftMenu.yaml
  plugin/cpanel/theme/index.live.php plugin/cpanel/uapi/BladeCPMigrator.pm
  plugin/cpanel/adminbin/MigratorClient plugin/cpanel/adminbin/MigratorClient.conf
  docs/installation.md docs/configuration.md docs/compatibility.md docs/troubleshooting.md
  docs/release-process.md docs/repository-governance.md
  assets/README.md assets/logo-dark.svg assets/logo-light.svg
  assets/hero.en.svg assets/hero-compact.en.svg assets/architecture.en.svg assets/architecture-compact.en.svg
  assets/hero.pt-BR.svg assets/hero-compact.pt-BR.svg assets/architecture.pt-BR.svg assets/architecture-compact.pt-BR.svg
  assets/hero.es.svg assets/hero-compact.es.svg assets/architecture.es.svg assets/architecture-compact.es.svg
  .github/workflows/ci.yml .github/pull_request_template.md .github/ISSUE_TEMPLATE/config.yml
  .github/ISSUE_TEMPLATE/bug_report.yml .github/ISSUE_TEMPLATE/feature_request.yml
  tests/run.php tests/install/run.sh tests/perl/run.sh
)
for path in "${required[@]}"; do
  [ -f "$path" ] || { echo "error: required public file is missing: $path" >&2; exit 1; }
done

# README screenshots: exactly the same set in each language, each one shown by
# the README of its language.
screenshots=(whm-migrations whm-accounts whm-review whm-settings whm-settings-highlights cpanel-app-start cpanel-app-track cpanel-banner)
[ "$(find assets/screenshots -mindepth 1 -maxdepth 1 2>/dev/null | sed 's#.*/##' | sort | tr '\n' ' ')" = 'en es pt-BR ' ] \
  || { echo "error: assets/screenshots must hold only en, pt-BR and es" >&2; exit 1; }
for lang in en pt-BR es; do
  expected="$(printf '%s.png\n' "${screenshots[@]}" | sort)"
  actual="$(find "assets/screenshots/$lang" -mindepth 1 -maxdepth 1 2>/dev/null | sed 's#.*/##' | sort)"
  [ "$expected" = "$actual" ] || { echo "error: assets/screenshots/$lang must hold exactly: ${screenshots[*]} (png)" >&2; exit 1; }
  readme=README.md
  [ "$lang" = en ] || readme="README.$lang.md"
  for shot in "${screenshots[@]}"; do
    grep -Fq "assets/screenshots/$lang/$shot.png" "$readme" || { echo "error: $readme does not show assets/screenshots/$lang/$shot.png" >&2; exit 1; }
  done
done

if find . -path ./.git -prune -o -type l -print | grep -q .; then
  echo "error: symbolic links are not allowed in the public tree" >&2
  find . -path ./.git -prune -o -type l -print >&2
  exit 1
fi

# Version: one source (plugin/VERSION), a dated changelog entry and README
# downloads of the same release.
VERSION="$(tr -d '[:space:]' < plugin/VERSION)"
printf '%s' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || { echo "error: plugin/VERSION is not semantic: $VERSION" >&2; exit 1; }
grep -Eq "^## \[$VERSION\] - [0-9]{4}-[0-9]{2}-[0-9]{2}$" CHANGELOG.md || { echo "error: CHANGELOG.md has no dated entry for $VERSION" >&2; exit 1; }
for readme in README.md README.pt-BR.md README.es.md; do
  grep -Fq "https://github.com/bladecp/bladecp-cpanel/releases/download/v$VERSION/bladecp-cpanel-migrator-$VERSION.tar.gz" "$readme" \
    || { echo "error: $readme does not download release $VERSION" >&2; exit 1; }
done
head -n 1 LICENSE | grep -qx 'MIT License' || { echo "error: LICENSE is not the MIT License" >&2; exit 1; }

# The bracketed letters keep this line from matching itself on every grep.
if grep -RInE --exclude-dir=.git --include='*.md' --include='*.yml' --include='*.php' --include='*.sh' \
  '(^|[^[:alnum:]_])(DRAF[T]|TOD[O]|FIXM[E]|TB[D])([^[:alnum:]_]|$)' .; then
  echo "error: editorial markers are not allowed in the public tree" >&2
  exit 1
fi

if grep -RInE --exclude-dir=.git \
  '(bcp|vh)_(live|test)_[A-Za-z0-9]{16,}|-----BEGIN [A-Z ]*PRIVATE KEY-----|AKIA[0-9A-Z]{16}|Authorization[[:space:]]*[:=][[:space:]]*(Bearer|Basic)[[:space:]]+[A-Za-z0-9._-]{12,}' .; then
  echo "error: credential-like value found in the public tree" >&2
  exit 1
fi

python3 - "$ROOT" <<'PY'
import base64
import os
import re
import struct
import sys

root = os.path.abspath(sys.argv[1])
TEXT = {'.md', '.php', '.sh', '.yml', '.yaml', '.json', '.conf', '.css', '.cgi', '.pm', '.svg', '.tt', '.txt', ''}
errors = []

def files():
    for base, dirs, names in os.walk(root):
        dirs[:] = sorted(d for d in dirs if d != '.git')
        for name in sorted(names):
            yield os.path.join(base, name)

def rel(path):
    return os.path.relpath(path, root)

# Public addresses: only documentation ranges (RFC 5737) and loopback.
ipv4 = re.compile(r'(?<![\w.])(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?!\w|\.\d)')
allowed_ip = re.compile(r'^(192\.0\.2|198\.51\.100|203\.0\.113)\.\d{1,3}$|^127\.0\.0\.1$|^0\.0\.0\.0$')
# E-mail addresses: reserved example domains and the public contact only.
email = re.compile(r'[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)')
allowed_email_domain = re.compile(r'(^|\.)example(\.(com|net|org))?$|(^|\.)(test|invalid|localhost)$')

def check_png(path, data, label):
    if not data.startswith(b'\x89PNG\r\n\x1a\n'):
        errors.append(f'{label}: not a PNG file')
        return
    offset = 8
    while offset + 8 <= len(data):
        length, kind = struct.unpack('>I4s', data[offset:offset + 8])
        if kind in (b'tEXt', b'zTXt', b'iTXt', b'eXIf', b'tIME'):
            errors.append(f'{label}: PNG metadata chunk {kind.decode()}')
        offset += 12 + length
        if kind == b'IEND':
            break

for path in files():
    ext = os.path.splitext(path)[1].lower()
    data = open(path, 'rb').read()
    if ext == '.png':
        check_png(path, data, rel(path))
        continue
    if ext in ('.jpg', '.jpeg', '.gif', '.webp'):
        errors.append(f'{rel(path)}: only PNG and SVG images are expected in the public tree')
        continue
    if ext not in TEXT:
        continue
    try:
        text = data.decode('utf-8')
    except UnicodeDecodeError:
        errors.append(f'{rel(path)}: not UTF-8 text')
        continue
    for number, line in enumerate(text.splitlines(), 1):
        for match in ipv4.finditer(line):
            address = match.group(0)
            if all(int(part) <= 255 for part in match.groups()) and not allowed_ip.match(address):
                errors.append(f'{rel(path)}:{number}: public IP address {address}')
        for match in email.finditer(line):
            address = match.group(0)
            if address != 'dev@bladecp.com' and not allowed_email_domain.search(match.group(1).lower()):
                errors.append(f'{rel(path)}:{number}: e-mail address {address}')
    if ext == '.svg':
        lowered = text.lower()
        for bad in ('<script', '<foreignobject', '<metadata', 'href="http', "href='http", 'javascript:'):
            if bad in lowered:
                errors.append(f'{rel(path)}: SVG contains {bad}')
        if re.search(r'\son[a-z]+\s*=', lowered):
            errors.append(f'{rel(path)}: SVG has an event handler')
        for encoded in re.findall(r'data:image/png;base64,([A-Za-z0-9+/=]+)', text):
            check_png(path, base64.b64decode(encoded), rel(path) + ' (embedded PNG)')
    if ext in ('.md', '.sh') or os.sep + 'lang' + os.sep in path:
        if '\u2014' in text:
            errors.append(f'{rel(path)}: em dash in user-facing text')

# Local Markdown links must resolve inside the tree.
link = re.compile(r'!?(?:\[[^\]]*\])\(([^)\s]+)(?:\s+"[^"]*")?\)')
for path in files():
    if not path.lower().endswith('.md'):
        continue
    for match in link.finditer(open(path, encoding='utf-8').read()):
        target = match.group(1).strip('<>')
        if not target or target.startswith(('#', 'http://', 'https://', 'mailto:')):
            continue
        target = target.split('#', 1)[0]
        candidate = os.path.normpath(os.path.join(os.path.dirname(path), target))
        if not candidate.startswith(root + os.sep) or not os.path.exists(candidate):
            errors.append(f'broken local link: {rel(path)} -> {match.group(1)}')

if errors:
    print('error: public tree check failed:', file=sys.stderr)
    for item in errors:
        print(f'  {item}', file=sys.stderr)
    raise SystemExit(1)
PY

# shellcheck disable=SC2016 # The $ belong to the PHP code.
php -r '
require "plugin/src/bootstrap.php";
foreach (["en", "pt-BR", "es"] as $locale) {
    $entries = require "plugin/lang/" . $locale . ".php";
    if (!is_array($entries) || $entries === []) {
        fwrite(STDERR, "error: invalid catalogue " . $locale . "\n");
        exit(1);
    }
}
'

echo "Public package structure and hygiene checks passed."
