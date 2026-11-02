<?php

declare(strict_types = 1);

namespace Constup\AwsSecretsBundle\Tests\Fixtures;

class SecretHolder
{
    public string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }
}
