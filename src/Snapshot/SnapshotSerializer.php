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
 *
 * Binding context
 * ---------------
 * The HMAC covers the component name, key, state and effect deps. On its
 * own that proves a snapshot came out of this application, but not *who*
 * it was issued to: a snapshot one user obtained would verify for every
 * other user, forever. {@see withContext()} returns a serializer that mixes
 * an extra context string into the HMAC input, so a snapshot signed under
 * context X only verifies under context X.
 *
 * - UsePHP signs `SnapshotBehavior::Session` / `Shared` snapshots with the
 *   PHP session id as context. They live in `$_SESSION`, so a snapshot
 *   lifted from one session cannot be replayed into another. The flip
 *   side: `session_regenerate_id()` (e.g. after login) invalidates the
 *   snapshots stored under the old id — that is the intended trade-off.
 * - `SnapshotBehavior::Persistent` and `#[Component(storage: 'snapshot')]`
 *   round trips use the empty context. Their state travels in the URL /
 *   form and is deliberately replayable by anyone holding it.
 * - The empty context produces exactly the checksum the unbound serializer
 *   always produced, so existing snapshots stay valid.
 *
 * Snapshots carry no issue time and no expiry: a signed snapshot verifies
 * until the secret key (or, for bound snapshots, the session id) changes.
 */
final class SnapshotSerializer
{
    private const CHECKSUM_ALGORITHM = 'sha256';

    /**
     * @param string $context Binding context mixed into every HMAC; the
     *        empty string means "unbound" (see the class docblock).
     * @throws \InvalidArgumentException If $secretKey is empty.
     */
    public function __construct(
        private readonly string $secretKey,
        private readonly string $context = '',
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
     *
     * With a non-empty binding context the context is the first member of
     * the signed JSON object, so a bound and an unbound payload can never
     * be byte-identical. With the empty context the payload is exactly what
     * it was before contexts existed.
     */
    public function calculateChecksum(Snapshot $snapshot): string
    {
        // Create a deterministic string from snapshot data (excluding checksum)
        $payload = [
            'name' => $snapshot->componentName,
            'key' => $snapshot->key,
            'state' => $snapshot->state,
            'effectDeps' => $snapshot->effectDeps,
        ];
        if ($this->context !== '') {
            $payload = ['ctx' => $this->context] + $payload;
        }

        $data = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

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
     *
     * A non-empty binding context is length-prefixed in front of the payload
     * (`ctx:<len>:<context>:<payload>`) so the pair is unambiguous; with the
     * empty context the raw payload is signed, as before.
     */
    public function signString(string $payload): string
    {
        if ($this->context !== '') {
            $payload = sprintf('ctx:%d:%s:%s', strlen($this->context), $this->context, $payload);
        }

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
     *
     * The binding context is carried over.
     */
    public function withSecretKey(string $secretKey): self
    {
        return new self($secretKey, $this->context);
    }

    /**
     * Create a new serializer whose HMACs are bound to $context.
     *
     * A snapshot (or string) signed under one context fails verification
     * under any other context, including the empty one. Pass '' to get the
     * unbound serializer back. See the class docblock for how UsePHP uses
     * this to tie Session/Shared snapshots to the PHP session id.
     */
    public function withContext(string $context): self
    {
        return new self($this->secretKey, $context);
    }
}
