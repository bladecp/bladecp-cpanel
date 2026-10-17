package Cpanel::API::BladeCPMigrator;

# cPanel app of the BladeCP cPanel Migrator: UAPI functions status, start,
# act and claim, available to accounts with the site_migration feature. They run
# in the cPanel process of the logged-in account and forward a closed set of
# request fields to the root AdminBin BladeCP/MigratorClient, which acts only
# on that account (cPanel passes the caller UID, never a field). Installed by
# install.sh and removed by uninstall.sh.

use strict;
use warnings;

use Cpanel::AdminBin::Call ();

our %API = (
    _needs_feature => 'site_migration',
    status         => { allow_demo => 0 },
    start          => { allow_demo => 0 },
    act            => { allow_demo => 0 },
    claim          => { allow_demo => 0 },
);

my @FIELDS    = qw( session csrf job op domain );
my $MAX_FIELD = 254;

sub status { return _forward( 'STATUS', @_ ); }
sub start  { return _forward( 'START',  @_ ); }
sub act    { return _forward( 'ACT',    @_ ); }
sub claim  { return _forward( 'CLAIM',  @_ ); }

sub _forward {
    my ( $function, $args, $result ) = @_;
    my %input;
    for my $field (@FIELDS) {
        my $value = $args->get($field);
        next if !defined $value || ref $value || length $value > $MAX_FIELD;
        $input{$field} = "$value";
    }

    # Audit context reported by the cPanel session.
    $input{ip}        = substr( $ENV{REMOTE_ADDR} // '', 0, 45 );
    $input{team_user} = substr( $ENV{TEAM_USER}   // '', 0, 64 );

    my $data = eval { Cpanel::AdminBin::Call::call( 'BladeCP', 'MigratorClient', $function, \%input ) };
    if ( ref $data ne 'HASH' ) {
        $result->error('The site migration is not available right now.');
        return 0;
    }
    $result->data($data);
    return 1;
}

1;
