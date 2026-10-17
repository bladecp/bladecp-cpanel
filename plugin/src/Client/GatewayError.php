<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Client;

/** cPanel or the root side of the app did not answer. Never carries details to the page. */
final class GatewayError extends \RuntimeException
{
}
