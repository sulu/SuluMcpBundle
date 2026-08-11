Contributing
------------

Sulu MCP Bundle is an open source, community-driven project. We follow the same
coding standards as Symfony.

Before making a pull request please ensure you use the [Pull Request
Template](.github/PULL_REQUEST_TEMPLATE.md).

The test suite runs against MySQL 8.4. Start it and build the schema once:

```bash
docker compose -f tests/docker/docker-compose.mysql-84.yml up --wait
composer bootstrap-test-environment
```

The database binds to port 3306. Set `SULU_MCP_DB_PORT` to pick another one, and
point the test application at it in `tests/Application/.env.test.local`.

To browse the admin, build its assets once. The `sulu:admin:update-build` shortcut does not
work here because it reads the Sulu version from a `composer.lock` next to the kernel, which a
test application does not have:

```bash
cd tests/Application/assets/admin && npm install && npm run build
```

Run the following, in this order, before opening a pull request:

```bash
composer fix     # rector + php-cs-fixer
composer lint    # phpstan + cs check + rector dry-run + composer validate
composer test    # phpunit
```

Never skip `composer fix` — the license header and code style are enforced by
`composer lint`, and a missing header fails the build.

Useful links:

* [Creating a Pull Request](https://docs.sulu.io/en/3.x/developer/contributing/index.html): Sulu specific Pull Request Guide.
* [Coding Standards](http://symfony.com/doc/current/contributing/code/index.html): General Symfony coding standards.
* [General Developer Documentation](https://docs.sulu.io/): General Sulu Developer documentation index.
