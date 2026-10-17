package Cpanel::JSON;

# Test double of the cPanel JSON helpers, on top of JSON::PP (core Perl).

use strict;
use warnings;

use JSON::PP ();

my $json = JSON::PP->new->canonical(1);

sub Dump { return $json->encode( $_[0] ); }
sub Load { return $json->decode( $_[0] ); }

1;
