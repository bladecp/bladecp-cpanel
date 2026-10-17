package Cpanel::AdminBin::Script::Call;

# Test double of the cPanel base class for full-mode AdminBins. It reads the
# function from TEST_ADMINBIN_FUNCTION, the caller from TEST_ADMINBIN_USER and
# the arguments (JSON) from TEST_ADMINBIN_ARGS, calls the function like
# cPanel does, and prints the returned payload as JSON.

use strict;
use warnings;

use Cpanel::JSON ();

sub run {
    my ($class) = @_;
    my $self     = bless { user => $ENV{TEST_ADMINBIN_USER} }, $class;
    my $function = $ENV{TEST_ADMINBIN_FUNCTION} // '';
    my %allowed  = map { $_ => 1 } $self->_actions();
    die "not allowed\n" if !$allowed{$function};
    my $args   = Cpanel::JSON::Load( $ENV{TEST_ADMINBIN_ARGS} // '{}' );
    my $result = $self->$function($args);
    print Cpanel::JSON::Dump( { status => 1, payload => [$result] } );
    return;
}

sub get_caller_username { return $_[0]->{user}; }

1;
