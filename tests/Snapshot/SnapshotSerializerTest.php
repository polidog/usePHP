<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Snapshot;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;
use Polidog\UsePhp\Snapshot\SnapshotVerificationException;

class SnapshotSerializerTest extends TestCase
{
    public function testSerializeWithoutSecretKey(): void
    {
        $serializer = new SnapshotSerializer();
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]);

        $json = $serializer->serialize($snapshot);
        $data = json_decode($json, true);

        $this->assertEquals('Counter', $data['memo']['name']);
        $this->assertEquals('main', $data['memo']['key']);
        $this->assertEquals([0 => 5], $data['state']);
        // Without secret key, checksum is empty HMAC
        $this->assertNotNull($data['checksum']);
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

    public function testVerifyChecksumWithoutSecretKeyAllowsNoChecksum(): void
    {
        $serializer = new SnapshotSerializer(); // No secret key
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]); // No checksum

        $this->assertTrue($serializer->verifyChecksum($snapshot));
    }

    public function testVerifyChecksumWithSecretKeyRequiresChecksum(): void
    {
        $serializer = new SnapshotSerializer('my-secret-key');
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]); // No checksum

        // With secret key, null checksum should fail
        $this->assertFalse($serializer->verifyChecksum($snapshot));
    }

    public function testHasSecretKey(): void
    {
        $withoutKey = new SnapshotSerializer();
        $withKey = new SnapshotSerializer('secret');

        $this->assertFalse($withoutKey->hasSecretKey());
        $this->assertTrue($withKey->hasSecretKey());
    }

    public function testWithSecretKey(): void
    {
        $original = new SnapshotSerializer();
        $withKey = $original->withSecretKey('new-secret');

        $this->assertFalse($original->hasSecretKey());
        $this->assertTrue($withKey->hasSecretKey());
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
}
