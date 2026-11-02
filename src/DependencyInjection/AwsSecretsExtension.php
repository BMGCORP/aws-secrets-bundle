<?php

declare(strict_types = 1);

namespace Constup\AwsSecretsBundle\DependencyInjection;

use Aws\SecretsManager\SecretsManagerClient;
use Constup\AwsSecretsBundle\AwsSecretsEnvVarProcessor;
use Constup\AwsSecretsBundle\Provider\AwsSecretsArrayEnvVarProvider;
use Constup\AwsSecretsBundle\Provider\AwsSecretsCachedEnvVarProvider;
use Constup\AwsSecretsBundle\Provider\AwsSecretsEnvVarProvider;
use Constup\AwsSecretsBundle\Service\SecretsManager;
use Constup\AwsSecretsBundle\Service\SecretsManagerInterface;
use Exception;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

class AwsSecretsExtension extends Extension
{
    const CACHE_NAMESPACE = 'aws_secrets';

    /**
     * Loads a specific configuration.
     *
     * @param array            $configs
     * @param ContainerBuilder $container
     *
     * @throws Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $configs = $this->processConfiguration($configuration, $configs);

        $container->setParameter('aws_secrets.ttl', $configs['ttl']);
        $container->setParameter('aws_secrets.ignore', $configs['ignore']);
        $container->setParameter('aws_secrets.delimiter', $configs['delimiter']);
        $container->setParameter('aws_secrets.secret_id', $configs['secret_id']);

        $container->register('aws_secrets.secrets_manager_client', SecretsManagerClient::class)
            ->setLazy(true)
            ->setPublic(false)
            ->addArgument($configs['client_config']['region'])
            ->addArgument($configs['client_config']['version'])
            ->addArgument($configs['client_config']['endpoint'])
            ->addArgument($configs['client_config']['credentials']['key'])
            ->addArgument($configs['client_config']['credentials']['secret'])
            ->setFactory([SecretsManagerClientFactory::class, 'createClient']);

        $container->setAlias('aws_secrets.client', 'aws_secrets.secrets_manager_client')
            ->setPublic(true);

        // Not a framework cache pool: pools get the cache logger and tracing decorators, and if any of them
        // needs a secret itself (e.g. Sentry DSN via env(aws:...)), resolving it recurses until memory runs out.
        if ($configs['cache'] === 'apcu') {
            $definition = new Definition(ApcuAdapter::class, [self::CACHE_NAMESPACE]);
        } elseif ($configs['cache'] === 'filesystem') {
            $definition = new Definition(FilesystemAdapter::class, [self::CACHE_NAMESPACE, 0, '%kernel.cache_dir%/' . self::CACHE_NAMESPACE]);
        } else {
            $definition = new Definition(ArrayAdapter::class);
        }

        $container->setDefinition('aws_secrets.cache', $definition);

        $container->register('aws_secrets.env_var_provider', AwsSecretsEnvVarProvider::class)
            ->setArgument('$secretsManagerClient', new Reference('aws_secrets.client'))
            ->setPublic(false);

        $container->register('aws_secrets.env_var_cached_provider', AwsSecretsCachedEnvVarProvider::class)
            ->setArgument('$cacheItemPool', new Reference('aws_secrets.cache'))
            ->setArgument('$decorated', new Reference('aws_secrets.env_var_provider'))
            ->setArgument('$ttl', $container->getParameter('aws_secrets.ttl'))
            ->setPublic(false);

        $container->register('aws_secrets.env_var_array_provider', AwsSecretsArrayEnvVarProvider::class)
            ->setArgument('$decorated', new Reference('aws_secrets.env_var_cached_provider'))
            ->setPublic(false);

        $container->register('aws_secrets.env_var_processor', AwsSecretsEnvVarProcessor::class)
            ->setArgument('$provider', new Reference('aws_secrets.env_var_array_provider'))
            ->setArgument('$ignore', $container->getParameter('aws_secrets.ignore'))
            ->setArgument('$delimiter', $container->getParameter('aws_secrets.delimiter'))
            ->setPublic(false)
            ->addTag('container.env_var_processor');

        $container->register('aws_secrets.secrets_manager', SecretsManager::class)
            ->setArgument('$client', new Reference('aws_secrets.client'))
            ->setArgument('$cache', new Reference('aws_secrets.cache'))
            ->setArgument('$secretId', $container->getParameter('aws_secrets.secret_id'))
            ->setArgument('$ttl', $container->getParameter('aws_secrets.ttl'))
            ->setPublic(true);

        $container->setAlias(SecretsManagerInterface::class, 'aws_secrets.secrets_manager')
            ->setPublic(true);
    }
}
