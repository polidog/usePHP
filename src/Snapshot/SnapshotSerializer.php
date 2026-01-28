<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Snapshot;

use Polidog\UsePhp\Runtime\Snapshot;

/**
 * Serializer for component snapshots.
 *
 * Handles serialization/deserialization with checksum verification
 * to prevent tampering of client-side state.
 */
final class SnapshotSerializer
{
    private const CHECKSUM_ALGORITHM = 'sha256';

    public function __construct(
        private readonly string $secretKey = '',
    ) {}

    /**
     * Serialize a snapshot to JSON with checksum.
     */
    public function serialize(Snapshot $snapshot): string
    {
        // Calculate checksum before serialization
        $checksum = $this->calculateChecksum($snapshot);
        $snapshotWithChecksum = $snapshot->withChecksum($checksum);

        return $snapshotWithChecksum->toJson();
    }

    /**
     * Deserialize and verify a snapshot from JSON.
     *
     * @throws SnapshotVerificationException If checksum verification fails
     */
    public function deserialize(string $json): Snapshot
    {
        $snapshot = Snapshot::fromJson($json);

        if (!$this->verifyChecksum($snapshot)) {
            throw new SnapshotVerificationException('Snapshot checksum verification failed');
        }

        return $snapshot;
    }

    /**
     * Deserialize without verification (for trusted sources).
     */
    public function deserializeWithoutVerification(string $json): Snapshot
    {
        return Snapshot::fromJson($json);
    }

    /**
     * Calculate checksum for a snapshot.
     */
    public function calculateChecksum(Snapshot $snapshot): string
    {
        // Create a deterministic string from snapshot data (excluding checksum)
        $data = json_encode([
            'name' => $snapshot->componentName,
            'key' => $snapshot->key,
            'state' => $snapshot->state,
            'effectDeps' => $snapshot->effectDeps,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        // HMAC with secret key for security
        return hash_hmac(self::CHECKSUM_ALGORITHM, $data, $this->secretKey);
    }

    /**
     * Verify the checksum of a snapshot.
     */
    public function verifyChecksum(Snapshot $snapshot): bool
    {
        if ($snapshot->checksum === null) {
            // If no secret key is set, allow snapshots without checksum
            return $this->secretKey === '';
        }

        $expectedChecksum = $this->calculateChecksum($snapshot);

        return hash_equals($expectedChecksum, $snapshot->checksum);
    }

    /**
     * Check if the serializer has a secret key configured.
     */
    public function hasSecretKey(): bool
    {
        return $this->secretKey !== '';
    }

    /**
     * Create a new serializer with a different secret key.
     */
    public function withSecretKey(string $secretKey): self
    {
        return new self($secretKey);
    }
}
