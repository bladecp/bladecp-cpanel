<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Client;

/**
 * What the cPanel page (running as the account) can ask cPanel for: the
 * three functions of the root side through UAPI, the account language and
 * the Jupiter header and footer. Production uses LiveApiGateway; tests use
 * a gateway that calls Backend directly.
 */
interface Gateway
{
    /**
     * Calls status, start or act.
     *
     * @param array<string,string> $args
     * @return array<string,mixed>
     * @throws GatewayError when cPanel or the root side does not answer
     */
    public function call(string $function, array $args): array;

    /** cPanel locale of the account (for example pt_br), or null. */
    public function locale(): ?string;

    /**
     * Jupiter header and footer around the app frame, or null when the
     * chrome is not available (the page then stands alone).
     *
     * @return array{0:string,1:string}|null
     */
    public function chrome(string $title): ?array;
}
