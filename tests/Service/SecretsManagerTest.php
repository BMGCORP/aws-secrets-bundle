<?php

declare(strict_types = 1);

namespace Constup\AwsSecretsBundle\Tests\Service;

use Aws\Result;
use Aws\SecretsManager\SecretsManagerClient;
use Constup\AwsSecretsBundle\Exception\InvalidJsonException;
use Constup\AwsSecretsBundle\Exception\KeyNotFoundException;
use Constup\AwsSecretsBundle\Exception\SecretNotFoundException;
use Constup\AwsSecretsBundle\Service\SecretsManager;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class SecretsManagerTest extends TestCase
{
    use ProphecyTrait;

    private $client;
    private $cache;
    private $manager;

    protected function setUp(): void
    {
        $this->client = $this->prophesize(SecretsManagerClient::class);
        $this->cache = $this->prophesize(CacheItemPoolInterface::class);

        $this->manager = new SecretsManager(
            $this->client->reveal(),
            $this->cache->reveal(),
            'my-secret',
            60
        );
    }

    /** @test */
    public function it_retrieves_secret_from_aws_when_cache_miss(): void
    {
        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(false);
        $cacheItem->set('secret-value')->shouldBeCalled()->willReturn($cacheItem);
        $cacheItem->expiresAfter(60)->shouldBeCalled()->willReturn($cacheItem);
        $this->cache->getItem('aws_secret.' . md5('my-secret'))
            ->willReturn($cacheItem);
        $this->cache->save($cacheItem->reveal())->shouldBeCalled();

        $result = $this->prophesize(Result::class);
        $result->get('SecretString')->willReturn(...)('secret-value');
        $this->client->getSecretValue(['SecretId' => 'my-secret'])
            ->willReturn($result);

        $value = $this->manager->getSecret();

        $this->assertEquals('secret-value', $value);
    }

    /** @test */
    public function it_retrieves_secret_from_cache_when_cache_hit(): void
    {
        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(true);
        $cacheItem->get()->willReturn('cached-value');
        $this->cache->getItem('aws_secret.' . md5('my-secret'))
            ->willReturn($cacheItem);

        $this->client->getSecretValue(Argument::any())->shouldNotBeCalled();

        $value = $this->manager->getSecret();

        $this->assertEquals('cached-value', $value);
    }

    /** @test */
    public function it_bypasses_cache_when_use_cache_is_false(): void
    {
        $this->cache->getItem(Argument::any())->shouldNotBeCalled();

        $result = $this->prophesize(Result::class);
        $result->get('SecretString')->willReturn(...)('secret-value');
        $this->client->getSecretValue(['SecretId' => 'my-secret'])
            ->willReturn($result);

        $value = $this->manager->getSecret(false);

        $this->assertEquals('secret-value', $value);
    }

    /** @test */
    public function it_throws_exception_when_secret_not_found(): void
    {
        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(false);
        $this->cache->getItem(Argument::any())->willReturn($cacheItem);

        $this->client->getSecretValue(['SecretId' => 'my-secret'])
            ->willThrow(new ResourceNotFoundException('test'));

        $this->expectException(SecretNotFoundException::class);

        $this->manager->getSecret();
    }

    /** @test */
    public function it_retrieves_specific_key_from_json_secret(): void
    {
        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(false);
        $cacheItem->set(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $cacheItem->expiresAfter(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $this->cache->getItem(Argument::any())->willReturn($cacheItem);
        $this->cache->save($cacheItem->reveal())->shouldBeCalled();

        $jsonSecret = json_encode(['username' => 'admin', 'password' => 'secret123']);
        $result = $this->prophesize(Result::class);
        $result->get('SecretString')->willReturn($jsonSecret);
        $this->client->getSecretValue(['SecretId' => 'my-secret'])
            ->willReturn($result);

        $value = $this->manager->getValue('password');

        $this->assertEquals('secret123', $value);
    }

    /** @test */
    public function it_throws_exception_when_json_key_not_found(): void
    {
        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(false);
        $cacheItem->set(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $cacheItem->expiresAfter(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $this->cache->getItem(Argument::any())->willReturn($cacheItem);
        $this->cache->save($cacheItem->reveal())->shouldBeCalled();

        $jsonSecret = json_encode(['username' => 'admin']);
        $result = $this->prophesize(Result::class);
        $result->get('SecretString')->willReturn($jsonSecret);
        $this->client->getSecretValue(['SecretId' => 'my-secret'])
            ->willReturn($result);

        $this->expectException(KeyNotFoundException::class);

        $this->manager->getValue('nonexistent');
    }

    /** @test */
    public function it_throws_exception_when_secret_is_not_valid_json(): void
    {
        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(false);
        $cacheItem->set(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $cacheItem->expiresAfter(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $this->cache->getItem(Argument::any())->willReturn($cacheItem);
        $this->cache->save($cacheItem->reveal())->shouldBeCalled();

        $result = $this->prophesize(Result::class);
        $result->get('SecretString')->willReturn(...)('not-json');
        $this->client->getSecretValue(['SecretId' => 'my-secret'])
            ->willReturn($result);

        $this->expectException(InvalidJsonException::class);

        $this->manager->getValue('key');
    }

    /** @test */
    public function it_retrieves_secret_as_array(): void
    {
        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(false);
        $cacheItem->set(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $cacheItem->expiresAfter(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $this->cache->getItem(Argument::any())->willReturn($cacheItem);
        $this->cache->save($cacheItem->reveal())->shouldBeCalled();

        $data = ['username' => 'admin', 'password' => 'secret123'];
        $jsonSecret = json_encode($data);
        $result = $this->prophesize(Result::class);
        $result->get('SecretString')->willReturn($jsonSecret);
        $this->client->getSecretValue(['SecretId' => 'my-secret'])
            ->willReturn($result);

        $array = $this->manager->getAsArray();

        $this->assertEquals($data, $array);
    }

    /** @test */
    public function it_updates_multiple_keys_in_json_secret(): void
    {
        // Get existing data
        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(false);
        $cacheItem->set(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $cacheItem->expiresAfter(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $this->cache->getItem(Argument::any())->willReturn($cacheItem);
        $this->cache->save($cacheItem->reveal())->shouldBeCalled();

        $existingData = ['username' => 'admin', 'password' => 'old', 'host' => 'localhost'];
        $result = $this->prophesize(Result::class);
        $result->get('SecretString')->willReturn(json_encode($existingData));
        $this->client->getSecretValue(['SecretId' => 'my-secret'])
            ->willReturn($result);

        // Update with merged data
        $updatedData = ['username' => 'admin', 'password' => 'new', 'host' => 'localhost'];
        $this->client->updateSecret([
            'SecretId' => 'my-secret',
            'SecretString' => json_encode($updatedData),
        ])->shouldBeCalled();

        $this->cache->deleteItem('aws_secret.' . md5('my-secret'))
            ->shouldBeCalled()
            ->willReturn(true);

        $this->manager->batchSetValues(['password' => 'new']);
    }

    /** @test */
    public function it_removes_key_from_json_secret(): void
    {
        // Get existing data
        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(false);
        $cacheItem->set(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $cacheItem->expiresAfter(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $this->cache->getItem(Argument::any())->willReturn($cacheItem);
        $this->cache->save($cacheItem->reveal())->shouldBeCalled();

        $existingData = ['username' => 'admin', 'password' => 'secret', 'old_key' => 'remove_me'];
        $result = $this->prophesize(Result::class);
        $result->get('SecretString')->willReturn(json_encode($existingData));
        $this->client->getSecretValue(['SecretId' => 'my-secret'])
            ->willReturn($result);

        // Update with key removed
        $updatedData = ['username' => 'admin', 'password' => 'secret'];
        $this->client->updateSecret([
            'SecretId' => 'my-secret',
            'SecretString' => json_encode($updatedData),
        ])->shouldBeCalled();

        $this->cache->deleteItem('aws_secret.' . md5('my-secret'))
            ->shouldBeCalled()
            ->willReturn(true);

        $this->manager->removeKey('old_key');
    }

    /** @test */
    public function it_removes_multiple_keys_from_json_secret(): void
    {
        // Get existing data
        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(false);
        $cacheItem->set(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $cacheItem->expiresAfter(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $this->cache->getItem(Argument::any())->willReturn($cacheItem);
        $this->cache->save($cacheItem->reveal())->shouldBeCalled();

        $existingData = ['keep' => 'this', 'remove1' => 'gone', 'remove2' => 'gone'];
        $result = $this->prophesize(Result::class);
        $result->get('SecretString')->willReturn(json_encode($existingData));
        $this->client->getSecretValue(['SecretId' => 'my-secret'])
            ->willReturn($result);

        // Update with keys removed
        $updatedData = ['keep' => 'this'];
        $this->client->updateSecret([
            'SecretId' => 'my-secret',
            'SecretString' => json_encode($updatedData),
        ])->shouldBeCalled();

        $this->cache->deleteItem('aws_secret.' . md5('my-secret'))
            ->shouldBeCalled()
            ->willReturn(true);

        $this->manager->batchRemove(['remove1', 'remove2']);
    }

    /** @test */
    public function it_updates_specific_key_in_json_secret(): void
    {
        // First call to get existing data
        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(false);
        $cacheItem->set(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $cacheItem->expiresAfter(Argument::any())->shouldBeCalled()->willReturn($cacheItem);
        $this->cache->getItem(Argument::any())->willReturn($cacheItem);
        $this->cache->save($cacheItem->reveal())->shouldBeCalled();

        $existingData = ['username' => 'admin', 'password' => 'old'];
        $result = $this->prophesize(Result::class);
        $result->get('SecretString')->willReturn(json_encode($existingData));
        $this->client->getSecretValue(['SecretId' => 'my-secret'])
            ->willReturn($result);

        // Then call to update
        $updatedData = ['username' => 'admin', 'password' => 'new'];
        $this->client->updateSecret([
            'SecretId' => 'my-secret',
            'SecretString' => json_encode($updatedData),
        ])->shouldBeCalled();

        $this->cache->deleteItem('aws_secret.' . md5('my-secret'))
            ->shouldBeCalled()
            ->willReturn(true);

        $this->manager->setValue('password', 'new');
    }

}
