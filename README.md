# atfm-tools

Air Traffic Flow Management toolset built on top of forks of the [ECFMP](https://github.com/ECFMP) project.

## Purpose

This repository is the home of custom ATFM tooling (dashboards, flow-measure
helpers, map visualisations) that sit on top of an existing Laravel flow
server and a EuroScope plugin SDK. The upstream projects live under the
[ECFMP](https://github.com/ECFMP) organisation; this repo pulls in personal
forks of them as git submodules under `vendor-forks/` so they can be
referenced, patched, or called over HTTP without losing the upstream lineage.

## Submodules

| Path | Fork | Upstream |
|------|------|----------|
| `vendor-forks/atfm-flow` | [skyelaird/ECFMP-flow](https://github.com/skyelaird/ECFMP-flow) | [ECFMP/flow](https://github.com/ECFMP/flow) — Laravel flow-measure backend |
| `vendor-forks/atfm-plugin-sdk` | [skyelaird/ECFMP-plugin-sdk](https://github.com/skyelaird/ECFMP-plugin-sdk) | [ECFMP/plugin-sdk](https://github.com/ECFMP/plugin-sdk) — C++ EuroScope plugin SDK |
| `vendor-forks/atfm-protobuf` | [skyelaird/ecfmp-protobuf](https://github.com/skyelaird/ecfmp-protobuf) | [ECFMP/ecfmp-protobuf](https://github.com/ECFMP/ecfmp-protobuf) — shared .proto schema |
| `vendor-forks/atfm-map-data` | [skyelaird/map_data](https://github.com/skyelaird/map_data) | [ECFMP/map_data](https://github.com/ECFMP/map_data) — global FIR geojson |

## Layout

```
atfm-tools/
├── LICENSE                 GPL-3.0
├── README.md               this file
├── composer.json           PHP dependencies for the tool code in src/
├── .gitmodules             submodule wiring
├── vendor-forks/           git submodules → personal forks of ECFMP repos
├── src/                    your new tool code
│   ├── Api/                thin REST client that talks to atfm-flow
│   ├── Measures/           flow-measure logic
│   └── Map/                FIR map rendering helpers
├── public/                 what you upload to IONOS webspace
│   ├── index.php
│   ├── assets/
│   └── fir.geojson         copied from vendor-forks/atfm-map-data
├── scripts/
│   ├── deploy-ionos.sh     rsync/ftp upload helper
│   └── gen-proto.sh        generates PHP stubs from .proto locally
└── tests/
```

## First-time clone

```bash
git clone --recurse-submodules https://github.com/skyelaird/atfm-tools.git
```

Or, if you already cloned without submodules:

```bash
git submodule update --init --recursive
```

## Updating a fork from upstream

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

## Deployment target

IONOS Web Hosting Ultimate (shared PHP 8.x + MySQL + SSH + cron). See
`scripts/deploy-ionos.sh` for the upload helper.

## License

GPL-3.0 — inherited from the upstream ECFMP projects. See `LICENSE`.
