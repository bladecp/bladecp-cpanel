<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Queue;

/**
 * Pure resume logic for the multipart backup upload.
 *
 * Upload state (persisted with the job, never with URLs):
 *   upload_id, part_size, size_bytes, total_parts, generation,
 *   parts: {"<partNumber>": "<etag>"}, resume_supported
 *
 * When the API answers with `uploadedParts` it supports resuming: that list
 * is authoritative for the returned upload. Without it (older API) every
 * call opens a new upload, so local progress only survives when the upload
 * id is unchanged.
 */
final class UploadPlan
{
    /**
     * @param array<string,mixed>|null $local
     * @param array<string,mixed> $response data of POST .../upload-url
     * @return array{state:array<string,mixed>,urls:array<int,string>}
     */
    public static function reconcile(?array $local, array $response, int $sizeBytes, string $generation): array
    {
        $uploadId = $response['uploadId'] ?? null;
        $partSize = $response['partSize'] ?? null;
        if (!is_string($uploadId) || $uploadId === '' || strlen($uploadId) > 1024) {
            throw new JobError(JobError::UPLOAD_PLAN_INVALID, 'The upload plan has no upload id.', true);
        }
        if (!is_int($partSize) || $partSize < 1 || $sizeBytes < 1) {
            throw new JobError(JobError::UPLOAD_PLAN_INVALID, 'The upload plan has an invalid part size.', true);
        }
        $total = intdiv($sizeBytes + $partSize - 1, $partSize);
        if ($total < 1 || $total > 10000) {
            throw new JobError(JobError::UPLOAD_PLAN_INVALID, 'The upload plan has an invalid number of parts.', true);
        }

        $urls = [];
        foreach (is_array($response['parts'] ?? null) ? $response['parts'] : [] as $part) {
            $number = $part['partNumber'] ?? null;
            $url = $part['url'] ?? null;
            if (is_int($number) && $number >= 1 && $number <= $total && is_string($url) && preg_match('#^https?://#i', $url) === 1) {
                $urls[$number] = $url;
            }
        }

        $sameUpload = $local !== null
            && ($local['upload_id'] ?? null) === $uploadId
            && ($local['part_size'] ?? null) === $partSize
            && ($local['size_bytes'] ?? null) === $sizeBytes
            && ($local['generation'] ?? null) === $generation;

        $resumeSupported = array_key_exists('uploadedParts', $response) && is_array($response['uploadedParts']);
        $done = [];
        if ($resumeSupported) {
            foreach ($response['uploadedParts'] as $part) {
                $number = is_array($part) ? ($part['partNumber'] ?? null) : null;
                $etag = is_array($part) ? ($part['etag'] ?? null) : null;
                if (is_int($number) && $number >= 1 && $number <= $total && is_string($etag) && $etag !== '') {
                    $done[$number] = $etag;
                }
            }
        } elseif ($sameUpload && is_array($local['parts'] ?? null)) {
            foreach ($local['parts'] as $number => $etag) {
                $number = (int) $number;
                if ($number >= 1 && $number <= $total && is_string($etag) && $etag !== '') {
                    $done[$number] = $etag;
                }
            }
        }
        ksort($done);

        $state = [
            'upload_id' => $uploadId,
            'part_size' => $partSize,
            'size_bytes' => $sizeBytes,
            'total_parts' => $total,
            'generation' => $generation,
            'resume_supported' => $resumeSupported,
            'parts' => self::encodeParts($done),
        ];
        return ['state' => $state, 'urls' => $urls];
    }

    /**
     * Part numbers still to upload, ascending.
     *
     * @param array<string,mixed> $state
     * @return list<int>
     */
    public static function missing(array $state): array
    {
        $done = self::parts($state);
        $missing = [];
        for ($n = 1; $n <= (int) $state['total_parts']; $n++) {
            if (!isset($done[$n])) {
                $missing[] = $n;
            }
        }
        return $missing;
    }

    /** @return array{0:int,1:int} offset and length of a part */
    public static function range(array $state, int $partNumber): array
    {
        $partSize = (int) $state['part_size'];
        $size = (int) $state['size_bytes'];
        $offset = ($partNumber - 1) * $partSize;
        return [$offset, max(0, min($partSize, $size - $offset))];
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public static function markDone(array $state, int $partNumber, string $etag): array
    {
        $done = self::parts($state);
        $done[$partNumber] = $etag;
        ksort($done);
        $state['parts'] = self::encodeParts($done);
        return $state;
    }

    /**
     * Parts for complete-upload, sorted by part number.
     *
     * @param array<string,mixed> $state
     * @return list<array{partNumber:int,etag:string}>
     */
    public static function completed(array $state): array
    {
        $parts = [];
        foreach (self::parts($state) as $number => $etag) {
            $parts[] = ['partNumber' => $number, 'etag' => $etag];
        }
        return $parts;
    }

    /** @param array<string,mixed> $state */
    public static function uploadedBytes(array $state): int
    {
        $bytes = 0;
        foreach (array_keys(self::parts($state)) as $number) {
            $bytes += self::range($state, $number)[1];
        }
        return $bytes;
    }

    /** @param array<string,mixed> $state */
    public static function percent(array $state): int
    {
        $size = (int) ($state['size_bytes'] ?? 0);
        return $size <= 0 ? 0 : (int) floor(self::uploadedBytes($state) * 100 / $size);
    }

    /**
     * @param array<string,mixed> $state
     * @return array<int,string>
     */
    private static function parts(array $state): array
    {
        $done = [];
        foreach (is_array($state['parts'] ?? null) ? $state['parts'] : [] as $number => $etag) {
            if (is_string($etag) && $etag !== '') {
                $done[(int) $number] = $etag;
            }
        }
        ksort($done);
        return $done;
    }

    /**
     * JSON objects keep string keys, so part numbers are stored as strings.
     *
     * @param array<int,string> $done
     * @return array<string,string>
     */
    private static function encodeParts(array $done): array
    {
        $encoded = [];
        foreach ($done as $number => $etag) {
            $encoded[(string) $number] = $etag;
        }
        return $encoded;
    }
}
