<?php

declare(strict_types = 1);

namespace Constup\AwsSecretsBundle\Service;

use Aws\Exception\AwsException;
use Aws\SecretsManager\SecretsManagerClient;
use Constup\AwsSecretsBundle\Exception\AwsSecretsException;
use Constup\AwsSecretsBundle\Exception\InvalidJsonException;
use Constup\AwsSecretsBundle\Exception\KeyNotFoundException;
use Constup\AwsSecretsBundle\Exception\SecretNotFoundException;
use JsonException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class SecretsManager implements SecretsManagerInterface
{
    const CACHE_KEY_PREFIX = 'aws_secret';
    private SecretsManagerClient $client;
    private CacheItemPoolInterface $cache;
    private string $secretId;
    private int $ttl;

    /**
     * Initializes the SecretsManager with AWS client, cache configuration, and the secret ID to manage.
     *
     * @param SecretsManagerClient   $client   AWS Secrets Manager client for API operations
     * @param CacheItemPoolInterface $cache    PSR-6 cache implementation for storing retrieved secrets
     * @param string                 $secretId The AWS secret ID or ARN to manage
     * @param int                    $ttl      Time-to-live for cached secrets in seconds
     */
    public function __construct(
        SecretsManagerClient $client,
        CacheItemPoolInterface $cache,
        string $secretId,
        int $ttl,
    ) {
        $this->client = $client;
        $this->cache = $cache;
        $this->secretId = $secretId;
        $this->ttl = $ttl;
    }

    /**
     * Retrieves the complete secret value from AWS Secrets Manager.
     * Checks cache first if enabled, then fetches from AWS and caches the result.
     *
     * @param bool $useCache Whether to use cache for this request (default: true)
     *
     * @return string The secret value as a string
     *
     * @throws SecretNotFoundException If the secret doesn't exist in AWS Secrets Manager
     * @throws AwsSecretsException     If AWS API returns an error
     */
    public function getSecret(bool $useCache = true): string
    {
        try {
            if ($useCache) {
                if ($cached = $this->getFromCache()) {
                    return $cached;
                }
            }

            $result = $this->client->getSecretValue([
                'SecretId' => $this->secretId,
            ]);

            $value = $result->get('SecretString');

            if ($useCache) {
                $this->storeInCache($value);
            }

            return $value;
        } catch (ResourceNotFoundException $e) {
            throw new SecretNotFoundException(
                "Secret '{$this->secretId}' not found in AWS Secrets Manager",
                0,
                $e
            );
        } catch (AwsException $e) {
            throw new AwsSecretsException(
                "AWS error retrieving secret '{$this->secretId}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Retrieves a specific key's value from the JSON secret.
     * Fetches the secret, parses it as JSON, and returns the value for the specified key.
     *
     * @param string $key      The JSON key to extract from the secret
     * @param bool   $useCache Whether to use cache for this request (default: true)
     *
     * @return string The value of the specified key as a string
     *
     * @throws SecretNotFoundException If the secret doesn't exist in AWS Secrets Manager
     * @throws InvalidJsonException    If the secret doesn't contain valid JSON
     * @throws KeyNotFoundException    If the specified key doesn't exist in the JSON secret
     * @throws AwsSecretsException     If AWS API returns an error
     */
    public function getValue(string $key, bool $useCache = true): string
    {
        $secretValue = $this->getSecret($useCache);

        try {
            $data = json_decode($secretValue, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidJsonException(
                "Secret '{$this->secretId}' does not contain valid JSON",
                0,
                $e
            );
        }

        if (!array_key_exists($key, $data)) {
            throw new KeyNotFoundException(
                "Key '{$key}' not found in secret '{$this->secretId}'"
            );
        }

        return (string)$data[$key];
    }

    /**
     * Retrieves the JSON secret and returns it as an array.
     * Fetches the secret and parses it as JSON, returning the entire decoded structure.
     *
     * @param bool $useCache Whether to use cache for this request (default: true)
     *
     * @return array The decoded JSON secret as an associative array
     *
     * @throws SecretNotFoundException If the secret doesn't exist in AWS Secrets Manager
     * @throws InvalidJsonException    If the secret doesn't contain valid JSON
     * @throws AwsSecretsException     If AWS API returns an error
     */
    public function getAsArray(bool $useCache = true): array
    {
        $secretValue = $this->getSecret($useCache);

        try {
            return json_decode($secretValue, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidJsonException(
                "Secret '{$this->secretId}' does not contain valid JSON",
                0,
                $e
            );
        }
    }


    /**
     * Updates a single key's value in the JSON secret.
     * Retrieves the current secret, modifies the specified key's value, and updates the entire secret.
     * Automatically invalidates the cache after successful update.
     *
     * @param string $key   The JSON key to update
     * @param mixed  $value The new value to set for the key
     *
     * @throws SecretNotFoundException If the secret doesn't exist in AWS Secrets Manager
     * @throws InvalidJsonException    If the secret doesn't contain valid JSON or encoding fails
     * @throws AwsSecretsException     If AWS API returns an error
     */
    public function setValue(string $key, mixed $value): void
    {
        $currentData = $this->getAsArray();
        $currentData[$key] = $value;

        try {
            $newValue = json_encode($currentData, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidJsonException(
                "Failed to encode updated secret data for '{$this->secretId}'",
                0,
                $e
            );
        }

        $this->updateSecretInternal($newValue);
    }


    /**
     * Removes a single key from the JSON secret.
     * Retrieves the current secret, removes the specified key, and updates the entire secret.
     * Automatically invalidates the cache after successful update.
     *
     * @param string $key The JSON key to remove
     *
     * @throws SecretNotFoundException If the secret doesn't exist in AWS Secrets Manager
     * @throws KeyNotFoundException    If the specified key doesn't exist in the JSON secret
     * @throws InvalidJsonException    If the secret doesn't contain valid JSON or encoding fails
     * @throws AwsSecretsException     If AWS API returns an error
     */
    public function removeKey(string $key): void
    {
        $currentData = $this->getAsArray();

        if (!array_key_exists($key, $currentData)) {
            throw new KeyNotFoundException(
                "Key '{$key}' not found in secret '{$this->secretId}'"
            );
        }

        unset($currentData[$key]);

        try {
            $newValue = json_encode($currentData, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidJsonException(
                "Failed to encode updated secret data for '{$this->secretId}'",
                0,
                $e
            );
        }

        $this->updateSecretInternal($newValue);
    }

    /**
     * Remove multiple keys from the JSON secret in a single operation.
     * Retrieves the current secret, removes all specified keys that exist, and updates the entire secret.
     * Silently skips keys that don't exist in the secret.
     * Automatically invalidates the cache after successful update.
     *
     * @param array $keys Array of JSON keys to remove
     *
     * @throws SecretNotFoundException If the secret doesn't exist in AWS Secrets Manager
     * @throws InvalidJsonException    If the secret doesn't contain valid JSON or encoding fails
     * @throws AwsSecretsException     If AWS API returns an error
     */
    public function batchRemove(array $keys): void
    {
        $currentData = $this->getAsArray();

        foreach ($keys as $key) {
            if (array_key_exists($key, $currentData)) {
                unset($currentData[$key]);
            }
        }

        try {
            $newValue = json_encode($currentData, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidJsonException(
                "Failed to encode updated secret data for '{$this->secretId}'",
                0,
                $e
            );
        }

        $this->updateSecretInternal($newValue);
    }

    /**
     * Retrieves multiple key values from the JSON secret in a single operation.
     * Fetches the secret once and extracts multiple keys, only returning keys that exist.
     *
     * @param array $keys     Array of JSON keys to retrieve
     * @param bool  $useCache Whether to use cache for this request (default: true)
     *
     * @return array Associative array of key-value pairs (only includes existing keys)
     *
     * @throws SecretNotFoundException If the secret doesn't exist in AWS Secrets Manager
     * @throws InvalidJsonException    If the secret doesn't contain valid JSON
     * @throws AwsSecretsException     If AWS API returns an error
     */
    public function batchGet(array $keys, bool $useCache = true): array
    {
        $data = $this->getAsArray($useCache);
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $result[$key] = $data[$key];
            }
        }

        return $result;
    }

    /**
     * Update multiple key values in the JSON secret in a single operation.
     * Retrieves the current secret, merges it with updates, and updates the entire secret.
     * Automatically invalidates the cache after successful update.
     *
     * @param array $updates Key-value pairs to update
     *
     * @throws SecretNotFoundException If the secret doesn't exist in AWS Secrets Manager
     * @throws InvalidJsonException    If the secret doesn't contain valid JSON or encoding fails
     * @throws AwsSecretsException     If AWS API returns an error
     */
    public function batchSetValues(array $updates): void
    {
        $currentData = $this->getAsArray();
        $newData = array_merge($currentData, $updates);

        try {
            $newValue = json_encode($newData, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidJsonException(
                "Failed to encode updated secret data for '{$this->secretId}'",
                0,
                $e
            );
        }

        $this->updateSecretInternal($newValue);
    }

    /**
     * Internal method to update the secret's value in AWS Secrets Manager.
     * Performs the actual AWS API call to update the secret and invalidates the cache.
     *
     * @param string $value The new secret value to store
     *
     * @throws SecretNotFoundException If the secret doesn't exist in AWS Secrets Manager
     * @throws AwsSecretsException     If AWS API returns an error
     */
    private function updateSecretInternal(string $value): void
    {
        try {
            $this->client->updateSecret([
                'SecretId' => $this->secretId,
                'SecretString' => $value,
            ]);
        } catch (ResourceNotFoundException $e) {
            throw new SecretNotFoundException("Secret not found: {$this->secretId}", 0, $e);
        } catch (AwsException $e) {
            throw new AwsSecretsException(
                "AWS error updating secret '{$this->secretId}': {$e->getMessage()}",
                0,
                $e
            );
        }

        $this->invalidateCacheInternal();
    }

    /**
     * Generates a cache key for the configured secret.
     * Uses MD5 hash to create a consistent, filesystem-safe key.
     *
     * @return string The generated cache key with prefix
     */
    private function getCacheKey(): string
    {
        return self::CACHE_KEY_PREFIX . '.' . md5($this->secretId);
    }

    /**
     * Retrieves the secret value from the cache if it exists.
     * Returns null if the secret is not cached or cache retrieval fails.
     *
     * @return string|null The cached secret value or null if not found
     */
    private function getFromCache(): ?string
    {
        try {
            $cacheKey = $this->getCacheKey();
            $cacheItem = $this->cache->getItem($cacheKey);

            return $cacheItem->isHit() ? $cacheItem->get() : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Stores the secret value in the cache with the configured TTL.
     * Silently fails if caching is not available or encounters an error.
     *
     * @param string $value The secret value to store in cache
     */
    private function storeInCache(string $value): void
    {
        try {
            $cacheKey = $this->getCacheKey();
            $cacheItem = $this->cache->getItem($cacheKey);

            $cacheItem->set($value);
            $cacheItem->expiresAfter($this->ttl);
            $this->cache->save($cacheItem);
        } catch (InvalidArgumentException) {
            // Silently fail if cache is not available
        }
    }

    /**
     * Internal method to invalidate the secret's cache entry.
     * Removes the cache item and returns success status.
     *
     * @return bool True if cache was successfully invalidated, false on error
     */
    private function invalidateCacheInternal(): bool
    {
        try {
            $cacheKey = $this->getCacheKey();

            return $this->cache->deleteItem($cacheKey);
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
