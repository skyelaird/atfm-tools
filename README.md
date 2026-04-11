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

## Deploying to IONOS

1. Fill in `IONOS_SSH_*` variables in `.env`.
2. Create the `atfm` MySQL database through the IONOS control panel; put
   its credentials in the server-side `.env`.
3. Point the domain's DocumentRoot at `public/`.
4. Run `./scripts/deploy-ionos.sh` from your local machine.
5. SSH in once and run `php bin/migrate.php` to create the tables.

## License

GPL-3.0 — inherited from the upstream ECFMP projects. See `LICENSE`.
