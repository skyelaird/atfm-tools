# atfm-tools

A minimal Air Traffic Flow Management toolset inspired by the
[ECFMP](https://github.com/ECFMP) project. Built to run on modest PHP + MySQL
shared hosting (IONOS Web Hosting Ultimate or similar) without Docker,
gRPC, Redis, or queue workers.

The upstream ECFMP projects are vendored as git submodules under
`vendor-forks/` for reference — this repo **does not run** atfm-flow; it
provides its own trimmed-down Slim + Eloquent implementation of the same
data model and API shape.

## Submodules (reference only)

| Path | Fork | Upstream |
|------|------|----------|
| `vendor-forks/atfm-flow` | [skyelaird/ECFMP-flow](https://github.com/skyelaird/ECFMP-flow) | [ECFMP/flow](https://github.com/ECFMP/flow) — Laravel flow-measure backend |
| `vendor-forks/atfm-plugin-sdk` | [skyelaird/ECFMP-plugin-sdk](https://github.com/skyelaird/ECFMP-plugin-sdk) | [ECFMP/plugin-sdk](https://github.com/ECFMP/plugin-sdk) — C++ EuroScope plugin SDK |
| `vendor-forks/atfm-protobuf` | [skyelaird/ecfmp-protobuf](https://github.com/skyelaird/ecfmp-protobuf) | [ECFMP/ecfmp-protobuf](https://github.com/ECFMP/ecfmp-protobuf) — shared .proto schema |
| `vendor-forks/atfm-map-data` | [skyelaird/map_data](https://github.com/skyelaird/map_data) | [ECFMP/map_data](https://github.com/ECFMP/map_data) — global FIR geojson |

## Stack

- PHP 8.2+
- [Slim 4](https://www.slimframework.com/) — router / front controller
- [Illuminate Database (Eloquent)](https://packagist.org/packages/illuminate/database) — ORM, no Laravel framework
- MySQL
- [Leaflet](https://leafletjs.com/) — map frontend

## Layout

```
atfm-tools/
├── LICENSE                  GPL-3.0
├── README.md                this file
├── composer.json            PHP dependencies
├── .env.example             copy to .env and edit
├── .gitmodules              vendor-forks wiring
├── vendor-forks/            git submodules → reference forks (not executed)
├── src/
│   ├── Bootstrap.php        loads .env and boots Eloquent
│   ├── Api/
│   │   ├── Kernel.php       Slim app builder + routes
│   │   └── FlowClient.php   (optional) HTTP client for a remote flow API
│   └── Models/
│       ├── Fir.php
│       └── FlowMeasure.php
├── public/                  webroot (DocumentRoot)
│   ├── index.php            Slim front controller
│   ├── .htaccess            rewrite everything → index.php
│   ├── map.html             Leaflet map UI
│   └── assets/
│       └── FIR_ECFMP.geojson (copied from vendor-forks/atfm-map-data)
├── bin/
│   ├── migrate.php          create schema (composer migrate)
│   └── seed.php             insert a few FIRs + demo flow measure
├── scripts/
│   ├── deploy-ionos.sh      rsync to IONOS over SSH
│   └── gen-proto.sh         protoc → PHP stubs (only if you need proto)
└── tests/
```

## Local development (Laragon)

Prereqs: PHP 8.2+, Composer, and MySQL — Laragon provides all three.

```
# 1. Clone with submodules
git clone --recurse-submodules https://github.com/skyelaird/atfm-tools.git
cd atfm-tools

# 2. Install PHP deps
composer install

# 3. Configure .env
copy .env.example .env
# edit .env if your MySQL credentials differ from root / blank

# 4. Create the atfm database (once)
#    In Laragon: right-click tray icon → MySQL → CREATE DATABASE atfm;
#    or from the CLI:
mysql -u root -e "CREATE DATABASE IF NOT EXISTS atfm CHARACTER SET utf8mb4;"

# 5. Run schema and seed
composer migrate
php bin/seed.php

# 6. Serve
composer serve
# → http://127.0.0.1:8080/api/health
# → http://127.0.0.1:8080/map.html
```

## API surface (v0.1)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/health` | Liveness probe |
| GET | `/api/v1/flight-information-region` | All FIRs |
| GET | `/api/v1/flight-information-region/{id}` | One FIR |
| GET | `/api/v1/flow-measure` | All flow measures |
| GET | `/api/v1/flow-measure?state=active` | Currently active flow measures |
| GET | `/api/v1/flow-measure/{id}` | One flow measure |

URL shapes deliberately mirror `ECFMP/flow`'s `/api/v1/*` so that the
EuroScope plugin SDK or any other client built against it can point at
this backend with minimal changes.

## Updating a vendor fork from upstream

```bash
cd vendor-forks/atfm-flow
git remote add upstream https://github.com/ECFMP/flow.git   # once
git fetch upstream
git merge upstream/main
git push origin main
cd ../..
git add vendor-forks/atfm-flow
git commit -m "Bump atfm-flow submodule"
git push
```

## Deploying to WHC (Web Hosting Canada)

Primary deployment target. WHC's Web Hosting Pro ships PHP 8.4 CLI, Composer
pre-installed, MariaDB, SSH, and cPanel — everything this project needs.

### First-time setup

1. **Pick PHP version**: cPanel → *Software* → *Select PHP Version* → set to
   **8.2** or higher → click *Set as current*. This changes the PHP used by
   web requests; CLI PHP is independent.
2. **Create the MySQL database**: cPanel → *Databases* → *MySQL Databases*.
   Create database `ogzqox66_atfm`, create user `ogzqox66_atfm` with a
   password, grant ALL privileges to the user on the database.
3. **SSH in, clone, install, configure**:
   ```bash
   ssh ogzqox66@173.209.32.98 -p 27
   cd ~
   git clone https://github.com/skyelaird/atfm-tools.git
   cd atfm-tools
   composer install --no-dev --optimize-autoloader
   cp .env.example .env
   nano .env                      # fill in DB_DATABASE, DB_USERNAME, DB_PASSWORD
   chmod 600 .env
   php bin/migrate.php
   php bin/seed.php               # optional demo data
   ```
4. **Create the subdomain**: cPanel → *Domains* → *Create a new subdomain*.
   - Subdomain: `atfm`
   - Domain: `momentaryshutter.com`
   - **Document Root**: `/home/ogzqox66/atfm-tools/public`
     *(important — points Apache at Slim's front controller, not the repo root)*
5. **Test**: `https://atfm.momentaryshutter.com/api/health`

### Subsequent updates

Every time you push to GitHub's `main` branch, deploy with:

```bash
ssh ogzqox66@173.209.32.98 -p 27
~/atfm-tools/scripts/deploy-whc.sh
```

The script pulls, runs `composer install --no-dev`, and re-runs the
idempotent migration.

## License

GPL-3.0 — inherited from the upstream ECFMP projects. See `LICENSE`.
