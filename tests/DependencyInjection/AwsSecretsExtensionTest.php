<?php

declare(strict_types = 1);

namespace Constup\AwsSecretsBundle\Tests\DependencyInjection;

use Constup\AwsSecretsBundle\DependencyInjection\AwsSecretsExtension;
use Constup\AwsSecretsBundle\Service\SecretsManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class AwsSecretsExtensionTest extends TestCase
{
    /**
     * @test
     * @dataProvider missingSecretIds
     */
    public function it_allows_missing_secret_id_regardless_of_ignore(array $config): void
    {
        $container = new ContainerBuilder();
        (new AwsSecretsExtension())->load([$config], $container);

        $this->assertFalse($container->hasDefinition('aws_secrets.secrets_manager'));
        $this->assertFalse($container->hasAlias(SecretsManagerInterface::class));
        $this->assertTrue($container->hasDefinition('aws_secrets.env_var_processor'));
        $container->compile();
    }

    public function missingSecretIds(): array
    {
        return [
            'omitted' => [[]],
            'null' => [['secret_id' => null]],
            'ignored omitted' => [['ignore' => true]],
            'ignored null' => [['ignore' => true, 'secret_id' => null]],
        ];
    }

    /**
     * @test
     * @dataProvider invalidSecretIds
     */
    public function it_rejects_invalid_secret_id_types(bool $ignore, mixed $secretId): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The "secret_id" option must be a string or null.');
        (new AwsSecretsExtension())->load([['ignore' => $ignore, 'secret_id' => $secretId]], new ContainerBuilder());
    }

    public function invalidSecretIds(): array
    {
        return [
            [false, false],
            [false, 123],
            [true, false],
            [true, 123],
        ];
    }

    /** @test */
    public function it_compiles_with_an_environment_secret_id(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new AwsSecretsExtension());
        $container->loadFromExtension('aws_secrets', ['secret_id' => '%env(AWS_SECRET)%']);
        $container->compile();

        $this->assertTrue($container->hasDefinition('aws_secrets.secrets_manager'));
        $this->assertTrue($container->hasAlias(SecretsManagerInterface::class));
    }

    /** @test */
    public function it_keeps_secrets_manager_available_when_ignored_with_an_id(): void
    {
        $container = new ContainerBuilder();
        (new AwsSecretsExtension())->load([['ignore' => true, 'secret_id' => 'secret']], $container);

        $this->assertSame('secret', $container->getDefinition('aws_secrets.secrets_manager')->getArgument('$secretId'));
        $this->assertSame('aws_secrets.secrets_manager', (string) $container->getAlias(SecretsManagerInterface::class));
    }

    /** @test */
    public function it_rejects_string_ignore_values(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new AwsSecretsExtension())->load([['ignore' => 'false', 'secret_id' => 'secret']], new ContainerBuilder());
    }

    /**
     * @test
     *
     * @dataProvider cacheAdapters
     */
    public function it_registers_cache_outside_framework_cache_pools(string $cache, string $class): void
    {
        $container = new ContainerBuilder();
        (new AwsSecretsExtension())->load([['cache' => $cache, 'secret_id' => 'secret']], $container);

        $definition = $container->getDefinition('aws_secrets.cache');

        $this->assertSame($class, $definition->getClass());
        $this->assertFalse($definition->hasTag('cache.pool'));
        $this->assertFalse($definition->hasMethodCall('setLogger'));
    }

    public function cacheAdapters(): array
    {
        return [
            'apcu' => ['apcu', ApcuAdapter::class],
            'filesystem' => ['filesystem', FilesystemAdapter::class],
            'array' => ['array', ArrayAdapter::class],
        ];
    }
}
