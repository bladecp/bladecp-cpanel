<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Queue;

use BladeCP\CpanelMigrator\Api\Client;
use BladeCP\CpanelMigrator\Api\Transport;
use BladeCP\CpanelMigrator\Support\Logger;

/**
 * Uploads a backup with one streamed PUT per part to the signed URLs
 * returned by the API. Progress is persisted after every part, so an
 * interrupted upload resumes from the first missing part.
 */
final class Uploader
{
    private const MAX_PART_ATTEMPTS = 4;
    private const MAX_PLAN_REFRESHES = 4;

    /** @var callable(int):void */
    private $sleep;

    public function __construct(
        private readonly Client $api,
        private readonly Transport $transport,
        private readonly Logger $log,
        ?callable $sleep = null
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param array{installation_id:string,external_client_id:string} $scope
     * @param array<string,mixed>|null $local previous upload state of this job
     * @param callable(array<string,mixed>):void $persist stores the upload state
     * @param callable(int):bool $tick receives uploaded bytes; false cancels
     * @return array<string,mixed> upload state with every part uploaded
     */
    public function upload(
        string $requestUuid,
        array $scope,
        string $path,
        string $generation,
        ?array $local,
        callable $persist,
        callable $tick
    ): array {
        clearstatcache(true, $path);
        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size <= 0) {
            throw new JobError(JobError::BACKUP_CHANGED, 'The backup file is missing.', true);
        }
        $resume = $local !== null
            && ($local['generation'] ?? null) === $generation
            && ($local['size_bytes'] ?? null) === $size
            && is_string($local['upload_id'] ?? null);
        $refreshes = 0;
        $plan = $this->plan($requestUuid, $scope, $path, $size, $generation, $resume ? $local : null, $resume);
        $state = $plan['state'];
        $urls = $plan['urls'];
        $persist($state);
        $this->log->info('upload.plan', [
            'request' => $requestUuid,
            'parts' => $state['total_parts'],
            'missing' => count(UploadPlan::missing($state)),
            'resumed' => $resume,
            'resume_supported' => $state['resume_supported'],
        ]);

        $failures = 0;
        while (true) {
            $missing = UploadPlan::missing($state);
            if ($missing === []) {
                return $state;
            }
            if ($tick(UploadPlan::uploadedBytes($state)) === false) {
                throw new JobError(JobError::CANCELLED, 'The upload was cancelled.');
            }
            $number = $missing[0];
            $url = $urls[$number] ?? null;
            if ($url === null) {
                // URLs for this part are not available: ask for a fresh plan.
                if (++$refreshes > self::MAX_PLAN_REFRESHES) {
                    throw new JobError(JobError::UPLOAD_URLS_UNAVAILABLE, 'No upload URL was returned for part ' . $number . '.', true);
                }
                $plan = $this->plan($requestUuid, $scope, $path, $size, $generation, $state, true);
                $state = $plan['state'];
                $urls = $plan['urls'];
                $persist($state);
                continue;
            }
            [$offset, $length] = UploadPlan::range($state, $number);
            $before = UploadPlan::uploadedBytes($state);
            $response = $this->transport->putFile($url, $path, $offset, $length, static fn (int $sent): bool => $tick($before + $sent));
            if ($response->transportError === 'aborted') {
                throw new JobError(JobError::CANCELLED, 'The upload was cancelled.');
            }
            if ($response->transportError === 'file_read_error') {
                throw new JobError(JobError::BACKUP_CHANGED, 'The backup file could not be read.', true);
            }
            $etag = $response->header('etag');
            if ($response->ok() && $etag !== null && trim($etag) !== '') {
                $state = UploadPlan::markDone($state, $number, trim($etag));
                $persist($state);
                $failures = 0;
                continue;
            }
            if ($response->status === 403 || $response->status === 404) {
                // Expired signature (403) or a closed upload (404): renew the plan.
                // A closed upload cannot be resumed, so it restarts from part 1.
                if (++$refreshes > self::MAX_PLAN_REFRESHES) {
                    throw new JobError(JobError::UPLOAD_URLS_UNAVAILABLE, 'The upload URLs were rejected (HTTP ' . $response->status . ').', true);
                }
                $resumeOpen = $response->status === 403;
                $plan = $this->plan($requestUuid, $scope, $path, $size, $generation, $resumeOpen ? $state : null, $resumeOpen);
                $state = $plan['state'];
                $urls = $plan['urls'];
                $persist($state);
                continue;
            }
            $failures++;
            $this->log->warning('upload.part_failed', [
                'request' => $requestUuid,
                'part' => $number,
                'status' => $response->status,
                'transport' => $response->transportError,
                'attempt' => $failures,
            ]);
            if ($failures >= self::MAX_PART_ATTEMPTS) {
                throw new JobError(
                    JobError::UPLOAD_PART_FAILED,
                    'Part ' . $number . ' failed ' . $failures . ' times (HTTP ' . $response->status . ').',
                    true
                );
            }
            ($this->sleep)(min(60, 5 * (2 ** ($failures - 1))));
        }
    }

    /**
     * @param array{installation_id:string,external_client_id:string} $scope
     * @param array<string,mixed>|null $local
     * @return array{state:array<string,mixed>,urls:array<int,string>}
     */
    private function plan(string $requestUuid, array $scope, string $path, int $size, string $generation, ?array $local, bool $resume): array
    {
        $response = $this->api->uploadUrl($requestUuid, $scope, $size, basename($path), $resume);
        return UploadPlan::reconcile($local, $response, $size, $generation);
    }
}
