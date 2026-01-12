<?php

declare(strict_types = 1);

namespace Constup\AwsSecretsBundle;

use Closure;
use Constup\AwsSecretsBundle\Provider\AwsSecretsEnvVarProviderInterface;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

class AwsSecretsEnvVarProcessor implements EnvVarProcessorInterface
{
    private string $delimiter;
    private array $decodedSecrets = [];
    private bool $ignore;
    private AwsSecretsEnvVarProviderInterface $provider;

    public function __construct(
        AwsSecretsEnvVarProviderInterface $provider,
        bool $ignore = false,
        string $delimiter = ','
    ) {
        $this->ignore = $ignore;
        $this->delimiter = $delimiter;
        $this->provider = $provider;
    }

    /**
     * Returns the value of the given variable as managed by the current instance.
     *
     * @param string  $prefix The namespace of the variable
     * @param string  $name   The name of the variable within the namespace
     * @param Closure $getEnv A closure that allows fetching more env vars
     *
     * @throws RuntimeException on error
     *
     * @return mixed
     */
    public function getEnv(string $prefix, string $name, Closure $getEnv): mixed
    {
        if ($this->ignore === true) {
            return $getEnv($name);
        }

        $value = $getEnv($name);

        if (empty($value)) {
            throw new RuntimeException(
                "Environment variable '{$name}' is empty. Expected format: 'secret_id{$this->delimiter}key'"
            );
        }

        $parts = explode($this->delimiter, $value);
        $secretId = trim($parts[0]);

        if (empty($secretId)) {
            throw new RuntimeException(
                "Secret ID is empty for environment variable '{$name}'. Expected format: 'secret_id{$this->delimiter}key'"
            );
        }

        $result = $this->provider->get($secretId);

        if (isset($parts[1])) {
            $key = trim($parts[1]);

            if (!isset($this->decodedSecrets[$secretId])) {
                $this->decodedSecrets[$secretId] = json_decode($result, true);
            }

            if (!isset($this->decodedSecrets[$secretId][$key])) {
                throw new RuntimeException(
                    "Key '{$key}' not found in secret '{$secretId}' for environment variable '{$name}'"
                );
            }

            return (string)$this->decodedSecrets[$secretId][$key];
        }

        return $result;
    }

    /**
     * @param bool $ignore
     */
    public function setIgnore(bool $ignore): void
    {
        $this->ignore = $ignore;
    }

    /**
     * @return string[] The PHP-types managed by getEnv(), keyed by prefixes
     * @codeCoverageIgnore
     */
    public static function getProvidedTypes(): array
    {
        return [
            'aws' => 'bool|int|float|string',
        ];
    }
}
