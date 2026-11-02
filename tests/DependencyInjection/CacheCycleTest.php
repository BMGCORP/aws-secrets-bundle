<?php

declare(strict_types = 1);

namespace Constup\AwsSecretsBundle\Tests\DependencyInjection;

use Constup\AwsSecretsBundle\Service\SecretsManager;
use Constup\AwsSecretsBundle\Tests\Fixtures\CacheCycleKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class CacheCycleTest extends TestCase
{
    private string $varDir;

    protected function setUp(): void
    {
        $this->varDir = sys_get_temp_dir() . '/aws-secrets-bundle-' . uniqid();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->varDir);
    }

    /**
     * @test
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function it_builds_cache_when_cache_channel_handler_needs_a_secret(): void
    {
        // A cycle recurses until memory runs out, keep it small so the failure is quick.
        ini_set('memory_limit', '256M');
        $_SERVER['TEST_SECRET'] = 'value';

        $kernel = new CacheCycleKernel($this->varDir);
        $kernel->boot();

        $this->assertInstanceOf(SecretsManager::class, $kernel->getContainer()->get('aws_secrets.secrets_manager'));
    }
}
