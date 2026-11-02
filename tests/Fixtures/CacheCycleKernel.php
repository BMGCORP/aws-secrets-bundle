<?php

declare(strict_types = 1);

namespace Constup\AwsSecretsBundle\Tests\Fixtures;

use Constup\AwsSecretsBundle\AwsSecretsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A handler of the cache channel needs a secret, like a Sentry handler with its DSN set via env(aws:...).
 */
class CacheCycleKernel extends Kernel
{
    use MicroKernelTrait;

    private string $varDir;

    public function __construct(string $varDir)
    {
        parent::__construct('test', false);
        $this->varDir = $varDir;
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new MonologBundle(), new AwsSecretsBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->varDir . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->varDir . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['test' => true, 'secret' => 'test']);
        $container->extension('aws_secrets', ['cache' => 'filesystem', 'ignore' => true, 'secret_id' => 'secret']);
        $container->extension('monolog', [
            'handlers' => [
                'secret_aware' => ['type' => 'service', 'id' => SecretAwareHandler::class, 'channels' => ['cache']],
            ],
        ]);

        $services = $container->services();
        $services->set(SecretHolder::class)->args(['%env(aws:TEST_SECRET)%']);
        $services->set(SecretAwareHandler::class)->args([new Reference(SecretHolder::class)]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}
