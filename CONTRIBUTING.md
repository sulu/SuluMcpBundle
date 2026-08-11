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

### Running the test application

`tests/Application` is a runnable Sulu installation. `APP_ENV` has to be exported rather
than set in `.env.local`, because Symfony skips `.env.local` whenever `APP_ENV` is a test
environment and `.env` pins it to `test`.

```bash
export APP_ENV=dev
echo 'DATABASE_URL="mysql://root:ChangeMe@127.0.0.1:3306/sulu_mcp_dev?serverVersion=8.4&charset=utf8mb4"' \
    > tests/Application/.env.local

composer generate-test-keys
tests/Application/bin/adminconsole doctrine:database:create --if-not-exists
tests/Application/bin/adminconsole sulu:build dev          # creates the admin/admin user
(cd tests/Application/assets/admin && npm install && npm run build)

cd tests/Application && symfony server:start
```

Two things to know. The admin JavaScript has to be built with npm as shown above:
`sulu:admin:update-build` cannot work here, because it reads the Sulu version from a
`composer.lock` next to the kernel and a test application has none. And `symfony server:start`
picks its own PHP, which may not be the one `vendor/` was installed with. If every route
returns a platform check error, pin it with `echo 8.4 > tests/Application/.php-version`.

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
