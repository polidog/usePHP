<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Snapshot;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;
use Polidog\UsePhp\Snapshot\SnapshotVerificationException;

class SnapshotSerializerTest extends TestCase
{
    public function testConstructorRejectsEmptySecretKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SnapshotSerializer('');
    }

    public function testSerializeWithSecretKey(): void
    {
        $serializer = new SnapshotSerializer('my-secret-key');
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]);

        $json = $serializer->serialize($snapshot);
        $data = json_decode($json, true);

        $this->assertNotNull($data['checksum']);
        $this->assertNotEmpty($data['checksum']);
    }

    public function testDeserializeWithValidChecksum(): void
    {
        $serializer = new SnapshotSerializer('my-secret-key');
        $original = new Snapshot('Counter', 'main', [0 => 5]);

        $json = $serializer->serialize($original);
        $restored = $serializer->deserialize($json);

        $this->assertEquals($original->componentName, $restored->componentName);
        $this->assertEquals($original->key, $restored->key);
        $this->assertEquals($original->state, $restored->state);
    }

    public function testDeserializeWithInvalidChecksumThrowsException(): void
    {
        $serializer = new SnapshotSerializer('my-secret-key');
        $original = new Snapshot('Counter', 'main', [0 => 5]);

        $json = $serializer->serialize($original);
        $data = json_decode($json, true);
        $data['checksum'] = 'invalid-checksum';
        $tamperedJson = json_encode($data);

        $this->expectException(SnapshotVerificationException::class);
        $serializer->deserialize($tamperedJson);
    }

    public function testDeserializeWithTamperedDataThrowsException(): void
    {
        $serializer = new SnapshotSerializer('my-secret-key');
        $original = new Snapshot('Counter', 'main', [0 => 5]);

        $json = $serializer->serialize($original);
        $data = json_decode($json, true);
        $data['state'][0] = 100; // Tamper with the state
        $tamperedJson = json_encode($data);

        $this->expectException(SnapshotVerificationException::class);
        $serializer->deserialize($tamperedJson);
    }

    public function testDeserializeWithoutVerification(): void
    {
        $serializer = new SnapshotSerializer('my-secret-key');

        $data = [
            'memo' => ['name' => 'Counter', 'key' => 'main'],
            'state' => [0 => 5],
            'effectDeps' => [],
            'checksum' => 'invalid-checksum',
        ];
        $json = json_encode($data);

        // Should not throw
        $snapshot = $serializer->deserializeWithoutVerification($json);
        $this->assertEquals('Counter', $snapshot->componentName);
    }

    public function testVerifyChecksum(): void
    {
        $serializer = new SnapshotSerializer('my-secret-key');
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]);

        // Add valid checksum
        $checksum = $serializer->calculateChecksum($snapshot);
        $snapshotWithChecksum = $snapshot->withChecksum($checksum);

        $this->assertTrue($serializer->verifyChecksum($snapshotWithChecksum));

        // Invalid checksum
        $snapshotWithInvalidChecksum = $snapshot->withChecksum('invalid');
        $this->assertFalse($serializer->verifyChecksum($snapshotWithInvalidChecksum));
    }

    public function testVerifyChecksumRequiresChecksum(): void
    {
        $serializer = new SnapshotSerializer('my-secret-key');
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]); // No checksum

        // With secret key (which is now required), a null checksum must fail.
        // The earlier `secretKey === ''` bypass — which would have accepted
        // any null-checksum snapshot as authentic — is gone.
        $this->assertFalse($serializer->verifyChecksum($snapshot));
    }

    public function testHasSecretKey(): void
    {
        $withKey = new SnapshotSerializer('secret');

        $this->assertTrue($withKey->hasSecretKey());
    }

    public function testWithSecretKey(): void
    {
        $original = new SnapshotSerializer('original-secret');
        $withKey = $original->withSecretKey('new-secret');

        $this->assertTrue($original->hasSecretKey());
        $this->assertTrue($withKey->hasSecretKey());
        // Verify the new serializer actually uses the new key by signing a
        // payload — different keys must produce different signatures.
        $this->assertNotSame(
            $original->signString('hello'),
            $withKey->signString('hello'),
        );
    }

    public function testCalculateChecksumIsDeterministic(): void
    {
        $serializer = new SnapshotSerializer('my-secret-key');
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]);

        $checksum1 = $serializer->calculateChecksum($snapshot);
        $checksum2 = $serializer->calculateChecksum($snapshot);

        $this->assertEquals($checksum1, $checksum2);
    }

    public function testDifferentSecretKeysProduceDifferentChecksums(): void
    {
        $serializer1 = new SnapshotSerializer('secret1');
        $serializer2 = new SnapshotSerializer('secret2');
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]);

        $checksum1 = $serializer1->calculateChecksum($snapshot);
        $checksum2 = $serializer2->calculateChecksum($snapshot);

        $this->assertNotEquals($checksum1, $checksum2);
    }

    public function testSignStringIsDeterministicAndVerifies(): void
    {
        $serializer = new SnapshotSerializer('my-secret-key');
        $sig1 = $serializer->signString('hello');
        $sig2 = $serializer->signString('hello');

        $this->assertSame($sig1, $sig2);
        $this->assertTrue($serializer->verifyString('hello', $sig1));
    }

    public function testVerifyStringRejectsTamperedPayload(): void
    {
        $serializer = new SnapshotSerializer('my-secret-key');
        $sig = $serializer->signString('hello');

        $this->assertFalse($serializer->verifyString('hello-tampered', $sig));
    }

    public function testVerifyStringRejectsWrongSecret(): void
    {
        $signer = new SnapshotSerializer('secret-a');
        $verifier = new SnapshotSerializer('secret-b');
        $sig = $signer->signString('hello');

        $this->assertFalse($verifier->verifyString('hello', $sig));
    }

    // ------------------------------------------------------------------
    // Binding context (withContext)
    // ------------------------------------------------------------------

    public function testEmptyContextProducesTheHistoricalChecksum(): void
    {
        // Pinned value: HMAC-SHA256 over the pre-context payload
        // {"name":"Counter","key":"main","state":[5],"effectDeps":[]} with
        // key "s3cret". If this changes, every snapshot already issued to
        // clients stops verifying.
        $expected = '4e2a329d66783817fdcc0ef0f10aa1d19ec9f408385d8aacb73d1d2cfb3d8999';
        $this->assertSame(
            $expected,
            hash_hmac('sha256', '{"name":"Counter","key":"main","state":[5],"effectDeps":[]}', 's3cret'),
        );

        $serializer = new SnapshotSerializer('s3cret');
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]);

        $this->assertSame($expected, $serializer->calculateChecksum($snapshot));
        $this->assertSame($expected, $serializer->withContext('')->calculateChecksum($snapshot));
    }

    public function testWithContextChangesChecksumAndIsRequiredToVerify(): void
    {
        $unbound = new SnapshotSerializer('s3cret');
        $boundA = $unbound->withContext('session-A');
        $boundB = $unbound->withContext('session-B');
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]);

        $this->assertNotSame($unbound->calculateChecksum($snapshot), $boundA->calculateChecksum($snapshot));
        $this->assertNotSame($boundA->calculateChecksum($snapshot), $boundB->calculateChecksum($snapshot));

        $jsonA = $boundA->serialize($snapshot);

        // Same context: round-trips.
        $this->assertSame([0 => 5], $boundA->deserialize($jsonA)->state);

        // Other context and no context: rejected.
        $this->assertFalse($boundB->verifyChecksum(Snapshot::fromJson($jsonA)));
        $this->assertFalse($unbound->verifyChecksum(Snapshot::fromJson($jsonA)));

        // And an unbound snapshot does not verify under a context.
        $jsonUnbound = $unbound->serialize($snapshot);
        $this->assertFalse($boundA->verifyChecksum(Snapshot::fromJson($jsonUnbound)));

        $this->expectException(SnapshotVerificationException::class);
        $boundB->deserialize($jsonA);
    }

    public function testWithContextDoesNotMutateTheOriginalSerializer(): void
    {
        $unbound = new SnapshotSerializer('s3cret');
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]);
        $before = $unbound->calculateChecksum($snapshot);

        $unbound->withContext('session-A');

        $this->assertSame($before, $unbound->calculateChecksum($snapshot));
    }

    public function testWithContextAppliesToSignString(): void
    {
        $unbound = new SnapshotSerializer('s3cret');
        $boundA = $unbound->withContext('session-A');
        $boundB = $unbound->withContext('session-B');

        $this->assertSame($unbound->signString('hello'), $unbound->withContext('')->signString('hello'));
        $this->assertNotSame($unbound->signString('hello'), $boundA->signString('hello'));
        $this->assertNotSame($boundA->signString('hello'), $boundB->signString('hello'));

        $sigA = $boundA->signString('hello');
        $this->assertTrue($boundA->verifyString('hello', $sigA));
        $this->assertFalse($boundB->verifyString('hello', $sigA));
        $this->assertFalse($unbound->verifyString('hello', $sigA));
    }

    public function testWithSecretKeyKeepsTheContext(): void
    {
        $boundA = new SnapshotSerializer('s3cret')->withContext('session-A');
        $rotated = $boundA->withSecretKey('n3w-secret');
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]);

        $this->assertSame(
            new SnapshotSerializer('n3w-secret')->withContext('session-A')->calculateChecksum($snapshot),
            $rotated->calculateChecksum($snapshot),
        );
    }
}
