#!/usr/bin/env bash
#
# Perl side of the cPanel app, with test doubles of the cPanel modules
# (tests/support/perl): syntax of the UAPI module and of the AdminBin, the
# fields the UAPI module forwards, and what the AdminBin passes to PHP (the
# account from cPanel, never a request field).

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
STUBS="$REPO/tests/support/perl"
BASE="$(mktemp -d "${TMPDIR:-/tmp}/bladecp-migrator-perl.XXXXXX")"
trap 'rm -rf "$BASE"' EXIT

PASS=0
FAIL=0
check() {
    local name="$1"
    shift
    if "$@"; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        printf '  FAIL  %s\n' "$name"
    fi
}

UAPI="$REPO/plugin/cpanel/uapi/BladeCPMigrator.pm"
ADMINBIN="$REPO/plugin/cpanel/adminbin/MigratorClient"

check "UAPI module compiles" bash -c "perl -I'$STUBS' -c '$UAPI' 2>/dev/null"
check "AdminBin compiles" bash -c "perl -I'$STUBS' -c '$ADMINBIN' 2>/dev/null"
check "AdminBin conf allows only cPanel callers" grep -qx 'allowed_parents=/usr/local/cpanel/cpanel,/usr/local/cpanel/uapi' "$REPO/plugin/cpanel/adminbin/MigratorClient.conf"
check "AdminBin runs in full mode" grep -qx 'mode=full' "$REPO/plugin/cpanel/adminbin/MigratorClient.conf"
check "UAPI module needs the site_migration feature" grep -q "_needs_feature => 'site_migration'" "$UAPI"

# UAPI module: only the known fields go to the AdminBin, plus the session context.
export TEST_ADMINBIN_LOG="$BASE/uapi.log"
export TEST_ADMINBIN_ANSWER='{"ok":true,"available":true}'
perl -I"$STUBS" -e '
    require $ARGV[0];
    package Args; sub new { bless $_[1], $_[0] } sub get { $_[0]->{$_[1]} }
    package Result; sub new { bless {}, $_[0] } sub data { $_[0]->{data} = $_[1] } sub error { $_[0]->{error} = $_[1] }
    package main;
    $ENV{REMOTE_ADDR} = "198.51.100.7"; $ENV{TEAM_USER} = "";
    my $result = Result->new;
    my $ok = Cpanel::API::BladeCPMigrator::start(Args->new({ session => "s" x 64, csrf => "c" x 64, email => "a\@example.com", user => "bob", plan_id => 1, job => "x" x 300 }), $result);
    print STDOUT ($ok && $result->{data}{ok} ? "ok\n" : "fail\n");
' "$UAPI" > "$BASE/uapi.out"
check "UAPI module answers with the AdminBin data" grep -qx ok "$BASE/uapi.out"
check "UAPI module calls BladeCP/MigratorClient START" grep -q '"function":"START","module":"MigratorClient","namespace":"BladeCP"' "$BASE/uapi.log"
check "UAPI module forwards only known fields (never an e-mail)" grep -qE '"args":\{"csrf":"c{64}","ip":"198.51.100.7","session":"s{64}","team_user":""\}' "$BASE/uapi.log"
check "UAPI module drops the account name, the e-mail and long values" bash -c "! grep -q 'bob\|plan_id\|xxxx\|example.com' '$BASE/uapi.log'"
export TEST_ADMINBIN_ANSWER='null'
perl -I"$STUBS" -e '
    require $ARGV[0];
    package Args; sub new { bless {}, $_[0] } sub get { undef }
    package Result; sub new { bless {}, $_[0] } sub data { $_[0]->{data} = $_[1] } sub error { $_[0]->{error} = $_[1] }
    package main;
    my $result = Result->new;
    my $ok = Cpanel::API::BladeCPMigrator::status(Args->new, $result);
    print STDOUT (!$ok && $result->{error} ? "error\n" : "fail\n");
' "$UAPI" > "$BASE/uapi-error.out"
check "UAPI module reports a generic error without details" grep -qx error "$BASE/uapi-error.out"

# AdminBin: the account comes from cPanel; PHP gets the function, --user and the filtered fields.
cat > "$BASE/fake-php" <<'FAKE'
#!/usr/bin/env bash
input="$(cat)"
printf '{"argv":"%s","input":%s}' "$*" "$input"
FAKE
chmod 0755 "$BASE/fake-php"
sed -e "s#@@PHP_BIN@@#$BASE/fake-php#" -e "s#@@PLUGIN_HOME@@#/plugin-home#" "$ADMINBIN" > "$BASE/MigratorClient"
TEST_ADMINBIN_FUNCTION=ACT TEST_ADMINBIN_USER=alice \
    TEST_ADMINBIN_ARGS='{"session":"s","csrf":"c","job":"j","op":"cancel","user":"bob","data_dir":"/tmp","email":"someone@victim.example"}' \
    perl -I"$STUBS" "$BASE/MigratorClient" > "$BASE/adminbin.out"
check "AdminBin passes the function and the cPanel account to PHP" grep -q '"argv":"-d display_errors=0 -d log_errors=0 /plugin-home/bin/client-admin.php act --user=alice"' "$BASE/adminbin.out"
check "AdminBin forwards only the known fields" grep -q '"input":{"csrf":"c","job":"j","op":"cancel","session":"s"}' "$BASE/adminbin.out"
check "AdminBin returns the PHP answer as the payload" grep -q '"status":1' "$BASE/adminbin.out"
TEST_ADMINBIN_FUNCTION=CLAIM TEST_ADMINBIN_USER=alice TEST_ADMINBIN_ARGS='{"session":"s","csrf":"c","job":"j"}' \
    perl -I"$STUBS" "$BASE/MigratorClient" > "$BASE/adminbin-claim.out"
check "AdminBin forwards claim for the cPanel account" grep -q 'client-admin.php claim --user=alice' "$BASE/adminbin-claim.out"
check "UAPI module offers claim" grep -q "^sub claim  { return _forward( 'CLAIM',  @_ ); }" "$UAPI"
check "AdminBin refuses a function outside its list" bash -c "! TEST_ADMINBIN_FUNCTION=MARK_PAID TEST_ADMINBIN_USER=alice perl -I'$STUBS' '$BASE/MigratorClient' >/dev/null 2>&1"
check "AdminBin refuses an invalid account name" bash -c "! TEST_ADMINBIN_FUNCTION=STATUS TEST_ADMINBIN_USER='../root' perl -I'$STUBS' '$BASE/MigratorClient' >/dev/null 2>&1"

# Side menu item: what Cpanel::Plugins::MenuBuilder loads (a YAML list of
# key, order and an SVG literal icon), read with the YAML parser of core Perl.
MENU="$REPO/plugin/cpanel/plugin/menu/LeftMenu.yaml"
if perl -MCPAN::Meta::YAML -e 1 2>/dev/null; then
    perl -MCPAN::Meta::YAML -e '
        open my $fh, "<", $ARGV[0] or die; local $/; my $yaml = CPAN::Meta::YAML->read_string(<$fh>) or die CPAN::Meta::YAML->errstr;
        my $items = $yaml->[0];
        my $ok = ref $items eq "ARRAY" && @$items == 1 && join(",", sort keys %{ $items->[0] }) eq "icon,key,order"
            && $items->[0]{key} eq "site_migration" && $items->[0]{order} =~ /^\d+$/ && $items->[0]{icon} =~ m{^<svg [^\n]+</svg>$};
        print $ok ? "ok\n" : "fail\n";
    ' "$MENU" > "$BASE/menu.out"
    check "LeftMenu.yaml is a list with one item (key, numeric order, SVG icon)" grep -qx ok "$BASE/menu.out"
fi

echo
if [ "$FAIL" -eq 0 ]; then
    echo "PASS: $PASS Perl checks"
    exit 0
fi
echo "FAIL: $FAIL failed, $PASS passed"
exit 1
