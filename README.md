# laravel-jobs

Deployed with [ox](https://deploywithox.com): deploy a repo to your own server with one command, no Docker. [Docs](https://deploywithox.com/docs) · [Guide for this stack](https://deploywithox.com/docs/guides/laravel)

> **Role in the zoo:** project `laravel-jobs` of [oxzoo-live](https://github.com/saurav-codes/oxzoo-live-control/blob/main/zoo/README.md#projects), deployed with [ox](https://deploywithox.com) on server s2 at https://laravel-jobs.s2.zoo.sorv.dev. The contract it follows is [DESIGN.md](https://github.com/saurav-codes/oxzoo-live-control/blob/main/zoo/DESIGN.md).

Part of [oxzoo-live](../README.md), server s2, `https://laravel-jobs.s2.zoo.sorv.dev`.
Laravel 12 on FrankenPHP with a MySQL (MariaDB 11.8) database, a Redis queue worker,
and the scheduler run by an ox cron every minute. It starts the `ping-pong` chain:
a queued job signs a webhook to rails-queue (s1), whose Solid Queue job signs an
ack back to `POST /api/acks`.

## What it proves

- P2: MySQL write, read and delete; Redis SET/GET/DEL with a TTL; a job dispatched
  on the Redis queue and run by the `queue` worker within 5 s (an honest failure
  when the worker is down); the scheduler's heartbeat row is younger than 150 s;
  `APP_KEY` encrypts and decrypts.
- P3: a signed `GET ${RAILS_URL}/_zoo/verify` (zoo-sig v1, `WEBHOOK_SECRET`) whose
  `name`, `key_fp` and `public_url` match.
- P4: `ping-pong` hops `queued`, `webhook-sent`, `ack-received` in MySQL table
  `zoo_hops`, shown at `GET /_zoo/trace/<id>` and on the status page `/`.

## ox features exercised

- PHP detection: FrankenPHP start serving `public/`, `/up` health, the Composer
  install, `php artisan migrate --force`, and apt `php-cli` plus the extensions the
  composer files ask for (`ext-pdo_mysql` gives `php-mysql`, `ext-curl` gives `php-curl`).
- `[workers] queue` (`php artisan queue:work`), `[cron] scheduler` (`schedule:run`
  every minute), `[build] commands` (a build stamp for `build.built_at`).
- `[services] mysql` (private MariaDB, `MYSQL_URL`) and `redis` (private, `REDIS_URL`).
- `[storage] keep = ["storage"]`: compiled Blade views and the probe lock file.

`ox.toml` has no `[app]` table: detection fills the FrankenPHP start and `/up` health
without one (an empty `[app]` was needed until ox d89326bc).

## Endpoints

| Path | What |
|------|------|
| `GET /` | status page: recent hops, scheduler heartbeat, queue depth |
| `GET /up` | Laravel's health page (ox's deploy health check) |
| `GET /_zoo/health`, `GET /_zoo/probe` | the contract (CORS from `ZOO_PANEL_ORIGIN`) |
| `GET /_zoo/verify` | signed by `rails-queue` with `WEBHOOK_SECRET` |
| `POST /_zoo/chain/ping-pong` | `{"trace": "<uuid>"}`, 202, 10 starts per minute |
| `GET /_zoo/trace/<id>` | hops of a trace |
| `POST /api/acks` | `{"trace"}`, signed by `rails-queue`, body capped at 64 KB |

## Variables

Provided by ox: `PORT`, `HOST`, `OX_ENV`, `OX_PROJECT`, `OX_RELEASE`, `OX_DATA_DIR`,
`PUBLIC_URL` (Laravel's `APP_URL`), `PUBLIC_HOST`, `MYSQL_URL` (read directly by
`config/database.php`), `REDIS_URL`.

Yours (all in `.env.example`):

| Key | Kind | Value |
|-----|------|-------|
| `APP_KEY` | secret | `php artisan key:generate --show` (a `base64:` value of 32 bytes) |
| `WEBHOOK_SECRET` | secret, shared | the zoo's shared value (signs webhooks, verifies acks and verify calls) |
| `RAILS_URL` | url | `https://rails-queue.s1.zoo.sorv.dev` |
| `ZOO_PANEL_ORIGIN` | plain | `https://zoo-control.s1.zoo.sorv.dev` |

Do not press **Generate** for `APP_KEY`: ox makes 43 URL-safe base64 characters,
which are neither 32 raw bytes nor a `base64:` key, and Laravel's encrypter refuses
them ("Unsupported cipher or incorrect key length"). Make the key with
`php artisan key:generate --show` and set it with `ox vars set laravel-jobs APP_KEY`.
The probe's `app-key` check fails with that hint when the key is wrong.

Fixed in config, not variables: `APP_DEBUG` false, logs to stderr, Redis client
predis (no PHP extension needed for the worker), cache and rate limit in Redis.

## Tests

```console
composer install
php vendor/bin/phpunit
```

They need no services (SQLite in memory, array cache, sync queue): the DESIGN.md
signing vectors, every verify failure reason, CORS for listed and unlisted origins,
trace id validation, signed acks, the 64 KB cap, the chain rate limit, missing
variables in the probe, and an ox-generated `APP_KEY` failing the probe. Last run:
`OK (13 tests, 90 assertions)`.

A local integration run (MariaDB 13 and Redis 8 on random ports, the PHP built-in
server, `queue:work`, `schedule:run`, and a small stand-in for rails-queue) passed
every probe check, the full `ping-pong` chain, the worker-down failure
(`no worker ran the probe job within 5000 ms`), and `probe busy` 429 for a third
concurrent probe. FrankenPHP itself was not run locally.

## ox check

```console
$ /tmp/oxz/ox check .
ox check /path/to/laravel-jobs (manifest: ox.toml)

  app.start                  mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs && XDG_CONFIG_HOME=/tmp XDG_DATA_HOME=/tmp exec frankenphp php-server --root public --listen 127.0.0.1:$PORT detected:artisan
  app.health                 /up                                                  detected:composer.lock
  build.install              composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader detected:composer.lock
  build.commands[0]          php artisan zoo:stamp                                declared
  build.migrate              php artisan migrate --force                          detected:artisan
  workers.queue              exec php artisan queue:work redis --sleep=1 --tries=3 --max-time=3600 declared
  cron.scheduler             * * * * *  php artisan schedule:run                  declared
  tools.github:php/frankenphp 1.13.0                                               default
  packages                   composer                                             detected:composer.json
  packages                   php-cli                                              detected:composer.json
  packages                   php-curl                                             detected:composer.json
  packages                   php-mbstring                                         detected:composer.lock
  packages                   php-mysql                                            detected:composer.json
  packages                   php-xml                                              detected:composer.lock
  packages                   unzip                                                detected:composer.json
  services.mysql             mysql 11 (only for this project)                     declared
  services.redis             redis 8 (only for this project)                      default

  Provided by ox: PORT, HOST, OX_ENV, OX_PROJECT, OX_RELEASE, OX_DATA_DIR, PUBLIC_URL, PUBLIC_HOST, MYSQL_URL, REDIS_URL
  Set on the dashboard before the first deploy: APP_KEY, WEBHOOK_SECRET, RAILS_URL, ZOO_PANEL_ORIGIN

Ready to deploy.
```
