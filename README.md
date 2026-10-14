# Trademinator Server

Trademinator Server is the central **data, intelligence, and coordination service** for the Trademinator project.

It is a Laravel application responsible for collecting public market data, maintaining historical datasets, generating technical features, training and serving machine-learning models, producing market intelligence, and exposing that information through web and API interfaces.

Trade execution is intentionally separated from the Server and belongs to the [Trademinator Client](https://github.com/Trademinator/client).

> **Project status:** active development. Interfaces, configuration, datasets, and model behaviour may change between versions.

## Architecture

At a high level, Trademinator is split into a server-side intelligence layer and a separate client-side execution layer.

```text
        Exchanges / public market data / public context sources
                              │
                              ▼
                    Trademinator Server
                 ┌────────────────────────┐
                 │ Market data ingestion  │
                 │ Historical OHLCV       │
                 │ Feature generation     │
                 │ Indicators             │
                 │ ML training/evaluation │
                 │ Signal intelligence    │
                 │ Web UI / API           │
                 │ Queues / scheduled jobs│
                 └────────────────────────┘
                    │        │        │
                    ▼        ▼        ▼
                 MariaDB   Redis   Archives
                              │
                              ▼
                    Trademinator Client
                              │
                              ▼
                           Exchange
```

The Server performs data acquisition, normalization, persistence, feature engineering, model training, model evaluation, and signal generation. Long-running work is handled through Laravel queues and scheduled jobs.

The Client is a separate project. It consumes server intelligence and is responsible for local execution-side behaviour. Exchange trading credentials and order execution are not part of the Server's role.

## Server responsibilities and scope

The Server is responsible for:

- discovering and maintaining supported markets;
- collecting and storing OHLCV market history;
- detecting gaps and backfilling historical data;
- calculating technical indicators and derived features;
- building, evaluating, publishing, and serving machine-learning models;
- producing market intelligence and signal outputs;
- collecting optional public market/context information;
- providing public, user, and administrative web interfaces;
- exposing APIs used by other Trademinator components;
- coordinating scheduled, queued, archival, and maintenance tasks.

The repository intentionally does **not** document private trading strategy details, internal operational knowledge, production data, or learned model state.

## Machine learning and supporting projects

Trademinator Server uses [Rubix ML](https://rubixml.com/) for machine-learning functionality.

It also uses and works alongside other Trademinator projects:

- [Trademinator Client](https://github.com/Trademinator/client) — separate client-side execution component.
- [Trademinator BCMath](https://github.com/Trademinator/bcmath) — shared arbitrary-precision mathematical helpers.
- [Trademinator Indicators](https://github.com/Trademinator/indicators) — shared technical-indicator implementation.

Public exchange connectivity is provided through [CCXT](https://github.com/ccxt/ccxt).

## Knowledge is not included

**The source repository does not contain Trademinator's learned knowledge.**

A fresh installation starts with no trained market intelligence. Production/staging database contents, historical observations, generated training datasets, model artifacts, human training data, and other learned state are not distributed with the source code.

A new installation must therefore collect or import market history, generate its own features and datasets, accumulate any required training information, and build its own models. In practical terms, **a newly installed server has to start learning from scratch** and should not be expected to reproduce the behaviour of an existing trained installation immediately.

## Minimum system requirements

A normal Linux/Laravel production stack is assumed.

Minimum practical baseline:

- 64-bit Linux;
- 2 CPU cores;
- 2 GiB RAM minimum, 4 GiB or more recommended for model building and history processing;
- PHP **8.4 or 8.5** with BCMath, Intl, PDO/MySQL and the standard Laravel runtime extensions;
- Composer 2;
- MariaDB 10.11+ or a compatible MySQL 8 installation;
- Redis for shared cache/history workloads;
- Node.js 20+ and npm for frontend asset compilation;
- Nginx or Apache with PHP-FPM;
- a process supervisor such as systemd or Supervisor for queue workers;
- cron or an equivalent scheduler;
- sufficient local storage for OHLCV history, generated datasets, logs, and archives.

Intelligence and historical-data jobs can be memory intensive. A PHP CLI `memory_limit` of at least **512M** is recommended for those workloads.

Storage requirements depend heavily on the number of exchanges, markets, candle periods, and amount of retained history.

## Installation

The following is intentionally abbreviated and assumes an administrator already familiar with Laravel deployment.

```bash
git clone https://github.com/Trademinator/trader-server.git
cd trader-server

composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate

# Configure application URL, MariaDB, Redis, mail, queues,
# Trademinator features and any optional external services in .env.

php artisan migrate --force

npm ci
npm run build

php artisan optimize
```

Configure the web server with `public/` as the document root and make `storage/` and `bootstrap/cache/` writable by the PHP runtime user.

A production installation also needs supervised Laravel queue workers for the queues enabled in `.env`, plus the Laravel scheduler, for example:

```cron
* * * * * cd /var/www/trademinator-server && php artisan schedule:run >/dev/null 2>&1
```

Queue concurrency, PHP memory limits, timeouts, Redis persistence, MariaDB tuning, log rotation, backups, and archive storage should be sized for the installation rather than copied blindly from another Trademinator deployment.

After installation, the Server still needs market history and model-building work before intelligence becomes available. Installing the application code alone does not install learned models or knowledge.

## Configuration

Start with `.env.example`. It documents the available application, database, queue, cache, market-data, intelligence, archive, API, and optional context settings.

Do not copy credentials, owner identifiers, API keys, databases, model artifacts, or other secrets/state from an existing installation unless you are deliberately migrating that installation.

## Staging server

The current Trademinator staging server is available at:

**https://server.trademinator.com/**

The staging environment may contain trained data and features that are **not** present in a fresh installation of this repository.

## Disclaimer

Trademinator is software for market-data processing, research, automation, and trading-system development. Nothing in this repository or in the signals produced by the software constitutes financial advice. Operators are responsible for deployment security, exchange configuration, risk controls, regulatory obligations, and any trading activity performed by connected clients.

## License

Trademinator Server is distributed under the MIT License.
