<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Api;

use BladeCP\CpanelMigrator\Support\Uuid;

/**
 * BladeCP Customer API client for partner migrations.
 *
 * Every migration call carries the installation scope
 * (installation_id + external_client_id). The API key is sent only in the
 * Authorization header and is never part of an error or log message.
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.bladecp.com/api/v1';
    private const TIMEOUT = 30;

    public function __construct(
        private readonly Transport $transport,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $version = BLADECP_MIGRATOR_VERSION
    ) {
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl === '' ? self::DEFAULT_BASE_URL : $this->baseUrl, '/');
    }

    /** Public connectivity probe. Sent without credentials. */
    public function health(): array
    {
        return $this->send('GET', '/customer/health', null, false, false);
    }

    public function me(): array
    {
        return $this->send('GET', '/customer/me');
    }

    /**
     * Public brand of the partner (white label name, or the partner name)
     * for the cPanel app. Logos are not used.
     *
     * @return array<string,mixed>
     */
    public function branding(): array
    {
        return $this->send('GET', '/customer/branding/assets');
    }

    /**
     * Partner plans that can receive a migration. Plans that explicitly
     * declare `migration.destinationEligible: false` are dropped.
     *
     * @return list<array<string,mixed>>
     */
    public function migrationPlans(): array
    {
        $plans = [];
        foreach ($this->listOf($this->send('GET', '/customer/partner/plans')) as $plan) {
            if (!is_array($plan) || !is_int($plan['id'] ?? null) || $plan['id'] <= 0) {
                continue;
            }
            $eligible = $plan['migration']['destinationEligible'] ?? null;
            if ($eligible === false) {
                continue;
            }
            $plans[] = $plan;
        }
        return $plans;
    }

    /** @return list<array<string,mixed>> */
    public function partnerLocations(int $planId): array
    {
        if ($planId <= 0) {
            return [];
        }
        $locations = [];
        foreach ($this->listOf($this->send('GET', '/customer/partner/locations?plan_id=' . $planId)) as $location) {
            if (is_array($location) && is_int($location['id'] ?? null) && $location['id'] > 0) {
                $locations[] = $location;
            }
        }
        return $locations;
    }

    /** @param array<string,mixed> $payload */
    public function createMigration(array $payload): array
    {
        return $this->send('POST', '/customer/migrations', $payload);
    }

    /** @param array{installation_id:string,external_client_id:string} $scope */
    public function getMigration(string $requestUuid, array $scope): array
    {
        return $this->send('GET', $this->migrationPath($requestUuid) . '?' . http_build_query($scope));
    }

    /**
     * @param array{installation_id:string,external_client_id:string} $scope
     * @return array<string,mixed>
     */
    public function uploadUrl(string $requestUuid, array $scope, int $sizeBytes, string $filename, bool $resume): array
    {
        $body = $scope + ['sizeBytes' => $sizeBytes, 'filename' => $filename];
        if ($resume) {
            // Older API versions ignore this field and open a new upload.
            $body['resume'] = true;
        }
        return $this->send('POST', $this->migrationPath($requestUuid, 'upload-url'), $body);
    }

    /**
     * @param array{installation_id:string,external_client_id:string} $scope
     * @param list<array{partNumber:int,etag:string}> $parts
     */
    public function completeUpload(string $requestUuid, array $scope, array $parts): array
    {
        return $this->send('POST', $this->migrationPath($requestUuid, 'complete-upload'), $scope + ['parts' => $parts]);
    }

    /** @param array{installation_id:string,external_client_id:string} $scope */
    public function confirmUpload(string $requestUuid, array $scope): array
    {
        return $this->send('POST', $this->migrationPath($requestUuid, 'confirm-upload'), $scope);
    }

    /** @param array{installation_id:string,external_client_id:string} $scope */
    public function bindService(string $requestUuid, array $scope, string $externalServiceId): array
    {
        return $this->send('POST', $this->migrationPath($requestUuid, 'service'), $scope + ['external_service_id' => $externalServiceId]);
    }

    /** @param array{installation_id:string,external_client_id:string} $scope */
    public function discoveryReview(string $requestUuid, array $scope, string $primaryDomain): array
    {
        return $this->send('POST', $this->migrationPath($requestUuid, 'discovery-review'), $scope + ['primaryDomain' => $primaryDomain]);
    }

    /** @param array{installation_id:string,external_client_id:string} $scope */
    public function approve(string $requestUuid, array $scope): array
    {
        return $this->send('POST', $this->migrationPath($requestUuid, 'approve'), $scope);
    }

    /** @param array{installation_id:string,external_client_id:string} $scope */
    public function cancel(string $requestUuid, array $scope, ?string $reason = null): array
    {
        $body = $scope;
        if ($reason !== null && $reason !== '') {
            $body['reason'] = substr($reason, 0, 2000);
        }
        return $this->send('POST', $this->migrationPath($requestUuid, 'cancel'), $body);
    }

    /**
     * Client-area URL where the account holder confirms the account
     * (`claim_required`). The URL carries a token: never store or log it.
     *
     * @param array{installation_id:string,external_client_id:string} $scope
     * @return array<string,mixed> url and expires_at
     */
    public function claimUrl(string $requestUuid, array $scope): array
    {
        return $this->send('POST', $this->migrationPath($requestUuid, 'claim-url'), $scope);
    }

    /** @param array{installation_id:string,external_client_id:string} $scope */
    public function retry(string $requestUuid, array $scope): array
    {
        return $this->send('POST', $this->migrationPath($requestUuid, 'retry'), $scope);
    }

    /** @param array{installation_id:string,external_client_id:string} $scope */
    public function commercialEvent(string $requestUuid, array $scope, string $event): array
    {
        return $this->send('POST', $this->migrationPath($requestUuid, 'commercial'), $scope + ['event' => $event]);
    }

    private function migrationPath(string $requestUuid, ?string $operation = null): string
    {
        if (!Uuid::isValid($requestUuid)) {
            throw new \InvalidArgumentException('Invalid migration request identifier.');
        }
        return '/customer/migrations/' . $requestUuid . ($operation === null ? '' : '/' . $operation);
    }

    /** @return list<mixed> */
    private function listOf(array $data): array
    {
        if (array_is_list($data)) {
            return $data;
        }
        foreach (['items', 'plans', 'locations'] as $key) {
            if (is_array($data[$key] ?? null) && array_is_list($data[$key])) {
                return $data[$key];
            }
        }
        return [];
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, ?array $body = null, bool $authenticated = true, bool $envelope = true): array
    {
        if ($authenticated && trim($this->apiKey) === '') {
            throw ApiError::notConfigured();
        }
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'BladeCP-cPanel-Migrator/' . $this->version,
            'X-Request-Source' => 'cpanel-migrator/' . $this->version,
            'X-Correlation-Id' => Uuid::v4(),
        ];
        if ($authenticated) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        $encoded = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $response = $this->transport->request($method, $this->baseUrl() . $path, $headers, $encoded, self::TIMEOUT);
        if (!$response->ok()) {
            throw ApiError::fromResponse($response, $path);
        }
        if ($response->body === '') {
            return [];
        }
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw ApiError::invalidResponse($path);
        }
        if (!$envelope) {
            return $decoded;
        }
        $data = $decoded['data'] ?? $decoded;
        return is_array($data) ? $data : [];
    }
}
