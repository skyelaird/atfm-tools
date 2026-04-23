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
| 1 | 4.9827 | N50.9274° W59.4084° | ~86 nm WSW of Strait of Belle Isle | `CZQX�QX_L_NORTH�000�285`, `CZQX�QX_L_EAST�000�285`, `CZQX�QX_L_WEST�000�285` | 102, 103, 104, 105, 106, 107, 108, 109 |
| 2 | 0.0000 | N47.8182° W60.6367° | ~46 nm ENE of Magdalen Islands | `CZQX�QX_L_WEST�000�285`, `CZQM�CAPE-BRETON-SECTOR�000�285`, `CZQM�CAPE-BRETON-N-SECTOR�000�285` | 61, 62, 63, 64, 68, 154, 155 |

## Band: HIGH

- Sectors polygonised: 18
- Total area (deg²): 400.7915
- Union area (deg²): 400.7299

| # | Area (deg²) | Centroid | Approx location | Neighbouring sectors | Sectorlines near boundary |
|---|---|---|---|---|---|
| 1 | 2.5325 | N48.3517° W71.0326° | ~4 nm SSE of Lac Saint-Jean QC | `CZUL�CZUL_LE�285�600`, `CZUL�CZUL_MC�285�600` | 191, 192, 208, 209, 219, 220, 221 |
| 2 | 0.0106 | N48.3105° W57.5409° | ~114 nm WSW of CYQX (Gander NL) | `CZQX�GANDER-HI2�285�600` | 88, 113, 114 |
| 3 | 0.0060 | N58.2144° W63.4898° | ~340 nm NNE of Labrador City | `CZQX�GANDER-HI6�285�600`, `CZUL�CZUL_SV�285�600`, `CZUL�CZUL_KR�285�600` | 133, 134, 135, 214, 216, 217, 227, 229 |
| 4 | 0.0032 | N52.2612° W64.2555° | ~104 nm ESE of Labrador City | `CZQM�MONCTON-HI4�285�600`, `CZQX�GANDER-HI5�285�600` | 98, 99, 130, 131 |
| 5 | 0.0007 | N61.5000° W63.0005° | ~533 nm NNE of Labrador City | `CZUL�CZUL_EW�285�600`, `CZQX�GANDER-HI6�285�600` | 11, 135, 136, 195, 199 |
| 6 | 0.0000 | N51.4851° W59.7381° | ~93 nm W of Strait of Belle Isle | — | — |

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
| `CZQX�CYQX_APP�000�285` | CZQX�CYQX_APP�000�285 | missing or empty sectorline CZQX�CYQX_APP�000�285 | `chain-CZQX-CYQX_APP-000-285.geojson` |
| `CZQX�CYYT_APP�000�285` | CZQX�CYYT_APP�000�285 | missing or empty sectorline CZQX�CYYT_APP�000�285 | `chain-CZQX-CYYT_APP-000-285.geojson` |