# AWS Secrets Bundle

[![GitHub license](https://img.shields.io/github/license/constup/aws-secrets-bundle?style=flat-square&color=green)](https://github.com/constup/aws-secrets-bundle/blob/master/LICENSE)
![PHP version](https://img.shields.io/badge/PHP-%5E7.4-blueviolet?style=flat-square)
![Symfony](https://img.shields.io/badge/Symfony-%5E5.3-blueviolet?style=flat-square)

Use AWS Secrets as service container parameters in Symfony.

## History and honorable mentions

This bundle is loosely based on and inspired by `incompass/aws-secrets-bundle` (https://github.com/casechek/aws-secrets-bundle).
The Incompass bundle is compatible with Symfony 3 and 4, and it looks like it's not updated/maintained anymore. This
package is built to be compatible with Symfony 5 and will, over time, get improvements.

If you are still using Symfony 3 or 4, please use the Incompass bundle instead of this one.

## Prerequisites

### Install AWS SDK

Since the official recommendation from Symfony is: `A bundle must not embed third-party PHP libraries.
It should rely on the standard Symfony autoloading instead.`, the `aws/aws-sdk-php` Composer package is included in this
bundle only as a dev dependency (for testing purposes).

You need to install AWS SDK for PHP in your project yourself:

```shell
composer require aws/aws-sdk-php
```

### AWS credentials

In order to connect to any AWS service (for example: AWS Secrets Manager), your application must be authenticated on
that AWS service. Since there are several scenarios for this, depending on your environment setup, configuring
environments and using credentials is covered here: [AWS credentials and authentication](./doc/aws_credentials.md)

## Installation

There are two main versions of AWS Secrets bundle: 1.x and 2.x.

Install version 1.x to use this package with Symfony 5.x (5.3+) and PHP 7.4.

    $ composer require constup/aws-secrets-bundle:^1

Install version 2.x to use this package with Symfony 6.x and PHP 8.x.

    $ composer require constup/aws-secrets-bundle:^2

## Configuration

By default, configuration for this bundle is loaded from `config/packages/aws_secrets.yaml` file or its
environment-specific alternatives (for example: `config/packages/test/aws_secrets.yaml`). The following configuration
properties are available:

```yaml
aws_secrets:
  client_config:
    region:           # Required if "ignore" is false.
    version: 'latest' # Defaults to "latest".
    endpoint: ~
    credentials:
        key: ~
        secret: ~
  cache: 'array'      # Can be one of the following: apcu, array, filesystem. Default is array.
  ttl: 60             # Cache TTL in seconds.
  delimiter: ','      # Delimiter to separate key from secret name.
  ignore: false       # Pass through AWS (for local dev environments set to "true").
  secret_id: ~        # Required: Default secret ID for the SecretsManager service.
```

## Usage

Set an env var to an AWS Secret Manager Secret name like so:

    AWS_SECRET=secret_name

If you want to grab a key in a JSON secret, you can separate the secret name and key:

    AWS_SECRET=secret_name,key

Set a parameter to this environment variable with the aws processor:

```yaml
parameters:
    my_parameter: '%env(aws:AWS_SECRET)%'
```

Your secret will now be loaded at runtime!

## Direct Secret Management

In addition to the environment variable processor, this bundle provides a `SecretsManager` service for direct programmatic access to AWS Secrets Manager. Each `SecretsManager` instance manages a single AWS secret.

### Default Service

The bundle automatically registers a default `SecretsManager` service using the `secret_id` from your configuration:

```yaml
# config/packages/aws_secrets.yaml
aws_secrets:
  secret_id: 'prod/database/credentials'  # Required
  # ... other config
```

You can then inject it using the interface:

```php
use Constup\AwsSecretsBundle\Service\SecretsManagerInterface;

class MyService
{
    public function __construct(
        private SecretsManagerInterface $secretsManager
    ) {}
}
```

Or use the service ID directly: `@aws_secrets.secrets_manager`

### Managing Multiple Secrets

If you need to manage multiple secrets, define additional services for each secret:

```yaml
# config/services.yaml
services:
    # Additional SecretsManager for API keys (beyond the default one)
    app.secrets.api:
        class: Constup\AwsSecretsBundle\Service\SecretsManager
        arguments:
            $client: '@aws_secrets.client'
            $cache: '@aws_secrets.cache'
            $secretId: 'prod/api/keys'  # Different secret ID
            $ttl: '%aws_secrets.ttl%'

    # Another SecretsManager for third-party credentials
    app.secrets.external:
        class: Constup\AwsSecretsBundle\Service\SecretsManager
        arguments:
            $client: '@aws_secrets.client'
            $cache: '@aws_secrets.cache'
            $secretId: 'prod/external/credentials'
            $ttl: '%aws_secrets.ttl%'
```

Then inject the specific services you need:

```php
use Constup\AwsSecretsBundle\Service\SecretsManagerInterface;

class MyService
{
    public function __construct(
        private SecretsManagerInterface $defaultSecrets,  // The default service
        private SecretsManagerInterface $apiSecrets,      // Custom service
        private SecretsManagerInterface $externalSecrets  // Custom service
    ) {}
}
```

In your service configuration:
```yaml
services:
    App\Service\MyService:
        arguments:
            $defaultSecrets: '@aws_secrets.secrets_manager'  # or '@Constup\AwsSecretsBundle\Service\SecretsManagerInterface'
            $apiSecrets: '@app.secrets.api'
            $externalSecrets: '@app.secrets.external'
```

### Retrieving Secrets

**Get the entire secret value:**

```php
// Get entire secret (uses cache by default)
$apiKey = $this->apiSecrets->getSecret();

// Bypass cache to get fresh value
$freshValue = $this->defaultSecrets->getSecret(useCache: false);
```

**Get a specific key from a JSON secret:**

```php
// Get single key from JSON secret
$password = $this->defaultSecrets->getValue('password');

// Get entire JSON secret as array
$credentials = $this->defaultSecrets->getAsArray();
// Returns: ['username' => 'admin', 'password' => 'secret123', ...]

// Get multiple keys at once
$dbConfig = $this->defaultSecrets->batchGet(['host', 'username', 'password']);
// Returns: ['host' => 'localhost', 'username' => 'admin', 'password' => 'secret123']
```

### Updating Secrets

**Update specific keys in a JSON secret:**

```php
// Update single key in JSON secret (merges with existing keys)
$this->defaultSecrets->setValue('password', 'new-password');

// Update multiple keys at once (merges with existing keys)
$this->defaultSecrets->batchSetValues([
    'password' => 'new-password',
    'host' => 'new-host'
]);
```

**Remove keys from a JSON secret:**

```php
// Remove a single key
$this->defaultSecrets->removeKey('old_key');

// Remove multiple keys at once
$this->defaultSecrets->batchRemove(['old_key1', 'old_key2']);
```

### Important Notes

- Each `SecretsManager` instance is bound to a **single secret** specified in its constructor
- All update operations (setValue, batchSetValues) automatically **merge** with existing keys in JSON secrets
- Cache is automatically invalidated after update or remove operations
- The `batchGet`, `batchSetValues`, and `batchRemove` methods operate on **keys within the same secret**, not across multiple secrets

### Required IAM Permissions

For direct secret management, your AWS credentials need the following permissions:

- `secretsmanager:GetSecretValue` - Required for reading secrets
- `secretsmanager:UpdateSecret` - Required for updating secrets
- `secretsmanager:PutSecretValue` - Required for updating secret values

Example IAM policy:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "secretsmanager:GetSecretValue",
        "secretsmanager:UpdateSecret",
        "secretsmanager:PutSecretValue"
      ],
      "Resource": "arn:aws:secretsmanager:REGION:ACCOUNT_ID:secret:*"
    }
  ]
}
```

## Development notes

- `master` branch only contains documentation.
- `di5` branch is compatible with PHP 7.4+ and Symfony Dependency Injection 5.3+. Release tags for this branch are 1.x.x
- `di6` branch is compatible with PHP 8.x and Symfony Dependency Injection 6.0+. Release tags for this branch are 2.x.x
- `di7` branch is compatible with PHP 8.x and Symfony Dependency Injection 7.0+. Release tags for this branch are 3.x.x

## Examples

* [Configure Doctrine to use AWS Secret values as MySQL connection parameters](./doc/sample_doctrine_mysql_connection.md)
