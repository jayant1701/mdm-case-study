# Laravel backend

This Laravel codebase runs in two roles selected by `APP_ROLE`:

- `provider` owns simulated Jamf device state, versions and the transactional outbox;
- `inventory` owns employees, assets, synchronization and durable event processing.

The roles use separate MySQL databases and route files. Start the complete application from the repository root with:

```sh
docker compose up --build
```

Run backend tests from this directory after installing development dependencies:

```sh
composer install
php vendor/bin/phpunit
php vendor/bin/pint --test
```

See the [main README](../Readme.md) for behavior, schema, API endpoints and design decisions. See [codebase onboarding](../docs/onboarding.md) for the service and interface map.
