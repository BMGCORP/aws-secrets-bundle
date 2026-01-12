<?php

declare(strict_types = 1);

namespace Constup\AwsSecretsBundle\Service;

interface SecretsManagerInterface
{
    /**
     * Retrieve the complete secret value.
     *
     * @param bool $useCache Whether to use cache (default: true)
     *
     * @return string The secret value (plain string or JSON)
     */
    public function getSecret(bool $useCache = true): string;

    /**
     * Retrieve a specific key's value from the JSON secret.
     *
     * @param string $key      The JSON key to extract
     * @param bool   $useCache Whether to use cache (default: true)
     *
     * @return string The value at the specified key
     */
    public function getValue(string $key, bool $useCache = true): string;

    /**
     * Retrieve all keys from the JSON secret as an associative array.
     *
     * @param bool $useCache Whether to use cache (default: true)
     *
     * @return array<string, mixed> The decoded JSON secret
     */
    public function getAsArray(bool $useCache = true): array;

    /**
     * Update a specific key's value in the JSON secret (preserves other keys).
     *
     * @param string $key   The JSON key to update
     * @param mixed  $value The new value for the key
     *
     * @return void
     */
    public function setValue(string $key, mixed $value): void;

    /**
     * Update multiple key values in the JSON secret in a single operation.
     *
     * @param array<string, mixed> $updates Key-value pairs to update
     *
     * @return void
     */
    public function batchSetValues(array $updates): void;

    /**
     * Remove a specific key from the JSON secret (preserves other keys).
     *
     * @param string $key The JSON key to remove
     *
     * @return void
     */
    public function removeKey(string $key): void;

    /**
     * Remove multiple keys from the JSON secret in a single operation.
     *
     * @param array<string> $keys JSON keys to remove
     *
     * @return void
     */
    public function batchRemove(array $keys): void;

    /**
     * Retrieve multiple key values from the JSON secret in a single operation.
     *
     * @param array<string> $keys Array of JSON keys to retrieve
     * @param bool          $useCache Whether to use cache (default: true)
     *
     * @return array<string, mixed> Associative array of key-value pairs (only includes existing keys)
     */
    public function batchGet(array $keys, bool $useCache = true): array;
}
