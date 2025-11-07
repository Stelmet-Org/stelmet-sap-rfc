# SAP RFC Connection — Setup Guide

This document explains how to configure and use the `ConnectionFactory` in this repository to create SAP RFC connections.

This README focuses on the connection setup and a small test script to verify connectivity.

---

## Requirements

- PHP 8.0+ (the code uses typed properties and null-safe operator)
- The SAP NW RFC PHP extension (commonly exposed as the `sapnwrfc` or `SAPNWRFC` extension). Ensure the extension is installed and enabled in your `php.ini`.
- Composer dependencies installed (`composer install`).
- Access to the SAP system (network, credentials, and permissions).


## Environment variables

By default `ConnectionFactory` reads connection values from environment variables. Set the following environment variables in your environment, a `.env` file, or your deployment configuration.

- `SAP_RFC_ASHOST` — SAP application server host (e.g. `sap.example.com`)
- `SAP_RFC_SYSNR` — SAP system number (usually 2 digits, e.g. `00`, `01`)
- `SAP_RFC_CLIENT` — SAP client number (e.g. `100`)
- `SAP_RFC_USER` — SAP username
- `SAP_RFC_PASSWD` — SAP password

You can also pass these parameters programmatically as an overrides array when creating a connection (see below).


## Installation

1. Install PHP extension for SAP RFC. How you install this depends on your platform and the extension distribution you use. Check your SAP connector vendor docs. After installing, verify it's loaded:

```bash
php -m | grep -i sap
```

You should see the extension listed (e.g. `sapnwrfc` or `SAPNWRFC`).

2. Install project dependencies:

```bash
composer install
```


## Using `ConnectionFactory`

`ConnectionFactory` is a small helper that reads environment variables (or accepts an overrides array) and returns an instance of `SAPNWRFC\Connection`.

Contract (high level):

- Input: optional array with keys `ashost`, `sysnr`, `client`, `user`, `passwd`.
- Output: a `SAPNWRFC\Connection` instance on success.
- Errors: throws `\InvalidArgumentException` if required keys are missing; throws `\RuntimeException` if the underlying connection fails.

Example usage:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Stelmet\SapRfc\ConnectionFactory;
use Psr\Log\NullLogger;

// Optional: pass a PSR-3 logger (any implementation). NullLogger is safe for testing.
$factory = new ConnectionFactory(new NullLogger());

try {
    // Option A: rely on environment variables
    $connection = $factory->create();

    // Option B: override values programmatically
    /*
    $connection = $factory->create([
        'ashost' => 'sap.example.com',
        'sysnr' => '00',
        'client' => '100',
        'user' => 'MYUSER',
        'passwd' => 'MYPASSWORD',
    ]);
    */

    // Use the $connection as needed (see SAPNWRFC extension docs)

    // close connection when finished (extension may provide a close/disconnect method)
    // $connection->close(); // check extension API

} catch (\InvalidArgumentException $e) {
    echo "Missing configuration: " . $e->getMessage() . "\n";
    exit(1);
} catch (\RuntimeException $e) {
    echo "Failed to connect to SAP: " . $e->getMessage() . "\n";
    exit(2);
}
```


## Minimal test script

Create a file `scripts/test_connection.php` (or run inline) with the example above to verify a connection.

Run it from CLI after setting environment variables (or use programmatic overrides):

```bash
export SAP_RFC_ASHOST=sap.example.com
export SAP_RFC_SYSNR=00
export SAP_RFC_CLIENT=100
export SAP_RFC_USER=MYUSER
export SAP_RFC_PASSWD=MYPASSWORD

php scripts/test_connection.php
```

Expected outcome: either the script connects successfully (no exceptions) or it prints a helpful error message.


## Logging

`ConnectionFactory` accepts an optional `Psr\Log\LoggerInterface` instance in the constructor. If provided, it will log connection attempts and errors. For simple testing use `Psr\Log\NullLogger` or any PSR-3 compatible logger (Monolog, etc.).


## Troubleshooting

- "Missing SAP config key" — ensure you provided all required env vars or override keys: `ashost`, `sysnr`, `client`, `user`, `passwd`.
- "SAP connection failed" — check network connectivity, credentials, and that the SAP gateway is reachable. Consult the SAPNWRFC extension error messages.
- Extension not loaded — verify the extension is installed and enabled in `php.ini` and that the web/CLI PHP instance loads the same `php.ini` you updated.


## Security notes

- Avoid checking credentials into source control.
- Use a secrets manager or environment configuration in production.
- Prefer service users with limited permissions for programmatic access.


## Next steps / Improvements

- Add a small integration test harness (requires access to a test SAP system).
- Provide a sample `.env.example` or environment setup helper for local development.


---

If you'd like, I can also add the `scripts/test_connection.php` example file to the repo and a `.env.example`. Which would you prefer?
