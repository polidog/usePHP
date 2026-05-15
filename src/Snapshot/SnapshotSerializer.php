<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Snapshot;

use Polidog\UsePhp\Runtime\Snapshot;

/**
 * Serializer for component snapshots.
 *
 * Handles serialization/deserialization with HMAC-SHA256 verification
 * to prevent tampering of client-side state.
 *
 * A non-empty secret key is required. The library no longer accepts an
 * empty key — earlier versions allowed it and silently disabled HMAC
 * verification, which let an attacker replay arbitrary state through
 * the snapshot round-trip. Callers should use a high-entropy key
 * (e.g. `bin2hex(random_bytes(32))`) loaded from configuration.
 */
final class SnapshotSerializer
{
    private const CHECKSUM_ALGORITHM = 'sha256';

    /**
     * @throws \InvalidArgumentException If $secretKey is empty.
     */
    public function __construct(
        private readonly string $secretKey,
    ) {
        if ($secretKey === '') {
            throw new \InvalidArgumentException(
                'SnapshotSerializer requires a non-empty secret key. '
                . 'Generate one with bin2hex(random_bytes(32)) and pass it '
                . 'via UsePHP::setSnapshotSecret().'
            );
        }
    }

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
     *
     * Snapshots without a checksum are always rejected — the secret key is
     * required at construction time, so there is no legitimate path that
     * produces an unsigned snapshot.
     */
    public function verifyChecksum(Snapshot $snapshot): bool
    {
        if ($snapshot->checksum === null) {
            return false;
        }

        $expectedChecksum = $this->calculateChecksum($snapshot);

        return hash_equals($expectedChecksum, $snapshot->checksum);
    }

    /**
     * Check if the serializer has a secret key configured.
     *
     * Always returns true now that an empty key is rejected by the
     * constructor. Retained for backwards compatibility with callers that
     * gated optional behavior on this.
     */
    public function hasSecretKey(): bool
    {
        return true;
    }

    /**
     * Sign an arbitrary string payload with the configured secret key.
     *
     * Used for embedding signed payloads in the HTML response (e.g. deferred
     * component placeholders) so the server can verify a round-tripped value
     * has not been tampered with.
     */
    public function signString(string $payload): string
    {
        return hash_hmac(self::CHECKSUM_ALGORITHM, $payload, $this->secretKey);
    }

    /**
     * Verify a payload + signature pair produced by signString().
     */
    public function verifyString(string $payload, string $signature): bool
    {
        return hash_equals($this->signString($payload), $signature);
    }

    /**
     * Create a new serializer with a different secret key.
     */
    public function withSecretKey(string $secretKey): self
    {
        return new self($secretKey);
    }
}
