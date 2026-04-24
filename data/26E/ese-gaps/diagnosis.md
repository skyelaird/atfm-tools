# Sector gap diagnosis — CZQQ ESE

Source: `airspace.ese.local`

Each gap below is a hole in the union of sector polygons in the 
given altitude band — airspace where no sector claims coverage. 
Slivers < 0.005 deg² (≈ 20×15 nm) are rounding artefacts where two 
FIRs' sectorlines don't share exact coordinates; values above that 
are meaningful.

## Band: LOW

- Sectors polygonised: 13
- Total area (deg²): 434.7066
- Union area (deg²): 434.7066

| # | Area (deg²) | Centroid | Approx location | Neighbouring sectors | Sectorlines near boundary |
|---|---|---|---|---|---|
| 1 | 4.9827 | N50.9274° W59.4084° | ~86 nm WSW of Strait of Belle Isle | `CZQX·QX_L_NORTH·000·285`, `CZQX·QX_L_EAST·000·285`, `CZQX·QX_L_WEST·000·285` | 92, 93, 94, 95, 96, 97, 98, 99 |

## Band: HIGH

- Sectors polygonised: 18
- Total area (deg²): 400.8439
- Union area (deg²): 400.8439

| # | Area (deg²) | Centroid | Approx location | Neighbouring sectors | Sectorlines near boundary |
|---|---|---|---|---|---|
| 1 | 2.5325 | N48.3517° W71.0326° | ~4 nm SSE of Lac Saint-Jean QC | `CZUL·CZUL_LE·285·600`, `CZUL·CZUL_MC·285·600` | 165, 166, 181, 182, 188, 189, 190 |

## Sectors with non-closable BORDER chain

Each sector below has a BORDER sequence whose sectorlines 
don't chain end-to-end into a closed ring. Open the linked 
`chain-*.geojson` in a map viewer; each sectorline renders as 
a separate LineString labelled by its position in BORDER order 
(e.g. `#3 of 7 · SL 27`). Look for where consecutive numbered 
segments don't meet — that's the break to fix in the 
sector construct tool.

| Sector | BORDER (in order) | Warnings | Chain GeoJSON |
|---|---|---|---|
| `CZQX·CYQX_APP·000·285` | CZQX·CYQX_APP·000·285 | missing or empty sectorline CZQX·CYQX_APP·000·285 | `chain-CZQX-CYQX_APP-000-285.geojson` |
| `CZQX·CYYT_APP·000·285` | CZQX·CYYT_APP·000·285 | missing or empty sectorline CZQX·CYYT_APP·000·285 | `chain-CZQX-CYYT_APP-000-285.geojson` |