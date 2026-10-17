package Cpanel::AdminBin::Call;

# Test double: records the call in TEST_ADMINBIN_LOG and answers from TEST_ADMINBIN_ANSWER.

use strict;
use warnings;

use Cpanel::JSON ();

sub call {
    my ( $namespace, $module, $function, $args ) = @_;
    if ( my $log = $ENV{TEST_ADMINBIN_LOG} ) {
        open( my $fh, '>>', $log ) or die "log: $!";
        print {$fh} Cpanel::JSON::Dump( { namespace => $namespace, module => $module, function => $function, args => $args } ), "\n";
        close $fh;
    }
    return Cpanel::JSON::Load( $ENV{TEST_ADMINBIN_ANSWER} // '{"ok":true}' );
}

1;
