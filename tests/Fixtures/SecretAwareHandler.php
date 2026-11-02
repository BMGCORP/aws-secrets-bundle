<?php

declare(strict_types = 1);

namespace Constup\AwsSecretsBundle\Tests\Fixtures;

use Monolog\Handler\NullHandler;

class SecretAwareHandler extends NullHandler
{
    public SecretHolder $holder;

    public function __construct(SecretHolder $holder)
    {
        parent::__construct();
        $this->holder = $holder;
    }
}
