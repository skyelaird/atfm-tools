"""Inject per-flight Monte Carlo simulation into the 26e-1/2/3.html lens pages.

Replaces the curve-level Gaussian smoothing + per-sector calibration
with a real per-flight MC: each booked flight gets its own σ_i derived
from √-scaling against grid wind RMSE, intervals shifted by ±σ_i,
re-aggregated into nominal/low/high counts per (sector, bin).
"""
import os
import re
import sys

sys.stdout.reconfigure(encoding='utf-8')

DIR = r'D:\GitHub\atfm-tools\public'
GRID_KT = {1: 8, 2: 12, 3: 17}

PRELOAD_BLOCK = r"""
    // Per-flight Monte Carlo simulation. Each booked flight gets its own
    // sigma_i from sqrt-scaling against grid wind RMSE; intervals shifted
    // by +/-sigma_i seconds and re-aggregated. Captures spatial wind-error
    // decorrelation (flights average ~5-7 independent samples across NAT).
    const SIGMA_GRID_KT = __SIGMA_GRID_KT__;
    const GS_KT = 460;
    const L_CORR_NM = 540;       // ~1000 km synoptic correlation length
    const L_CORR_HR = L_CORR_NM / GS_KT;
    function sigmaForFlight(tFlightMin) {
        if (tFlightMin <= 0) return 0;
        const tHr = tFlightMin / 60;
        return SIGMA_GRID_KT * Math.sqrt(tHr * L_CORR_HR) / GS_KT * 60;
    }
    function ctotToSec(s) {
        const parts = s.split(':');
        return parseInt(parts[0]) * 3600 + parseInt(parts[1]) * 60;
    }

    const BIN_MIN = DATA.meta.bin_minutes;
    const BIN_SEC = BIN_MIN * 60;

    const acc = { load: {}, load_low: {}, load_high: {},
                  pair_load: {}, pair_load_low: {}, pair_load_high: {} };
    for (const s of DATA.sectors) {
        acc.load[s.id] = {}; acc.load_low[s.id] = {}; acc.load_high[s.id] = {};
    }
    for (const p of (DATA.pairs || [])) {
        acc.pair_load[p.id] = {}; acc.pair_load_low[p.id] = {}; acc.pair_load_high[p.id] = {};
    }

    let totalFlights = 0, totalSigmaMin = 0;
    for (const fr of (DATA.flights || [])) {
        const sectorIds = Object.keys(fr.sectors || {});
        if (!sectorIds.length) continue;
        const ctotSec = ctotToSec(fr.ctot);
        let firstEntry = Infinity;
        for (const sid of sectorIds) {
            for (const iv of fr.sectors[sid]) {
                if (iv[0] < firstEntry) firstEntry = iv[0];
            }
        }
        const tFlightMin = (firstEntry - ctotSec) / 60;
        const sigmaMin = sigmaForFlight(tFlightMin);
        const sigmaSec = sigmaMin * 60;
        totalFlights++;
        totalSigmaMin += sigmaMin;

        const shifts = [-sigmaSec, 0, +sigmaSec];
        const targets = ['load_low', 'load', 'load_high'];
        const pairTargets = ['pair_load_low', 'pair_load', 'pair_load_high'];
        for (let k = 0; k < 3; k++) {
            const shift = shifts[k];
            const tgt = acc[targets[k]];
            const ptgt = acc[pairTargets[k]];
            const sectorBins = {};
            for (const sid of sectorIds) {
                sectorBins[sid] = new Set();
                for (const iv of fr.sectors[sid]) {
                    const start = iv[0], end = iv[1];
                    const startBin = Math.floor((start + shift) / BIN_SEC) * BIN_MIN;
                    const endBin   = Math.floor((end   + shift) / BIN_SEC) * BIN_MIN;
                    for (let b = startBin; b <= endBin; b += BIN_MIN) {
                        sectorBins[sid].add(b);
                    }
                }
            }
            for (const sid of sectorIds) {
                for (const b of sectorBins[sid]) {
                    tgt[sid][b] = (tgt[sid][b] || 0) + 1;
                }
            }
            for (const p of (DATA.pairs || [])) {
                const memberBins = new Set();
                for (const m of p.members) {
                    if (sectorBins[m]) {
                        for (const b of sectorBins[m]) memberBins.add(b);
                    }
                }
                for (const b of memberBins) {
                    ptgt[p.id][b] = (ptgt[p.id][b] || 0) + 1;
                }
            }
        }
    }

    function toRows(byBin) {
        return Object.entries(byBin)
            .map(([b, c]) => ({ bin_minute: parseInt(b), count: c }))
            .sort((a, b) => a.bin_minute - b.bin_minute);
    }
    window._mc = {};
    for (const which of ['load', 'load_low', 'load_high']) {
        window._mc[which] = {};
        for (const sid in acc[which]) window._mc[which][sid] = toRows(acc[which][sid]);
    }
    for (const which of ['pair_load', 'pair_load_low', 'pair_load_high']) {
        window._mc[which] = {};
        for (const pid in acc[which]) window._mc[which][pid] = toRows(acc[which][pid]);
    }
    window._mc.meanSigmaMin = totalFlights > 0 ? totalSigmaMin / totalFlights : 0;
    window._mc.totalFlights = totalFlights;
"""

BANDSFOR_FN = r"""// Bands sourced from per-flight Monte Carlo aggregates: low (-sigma
// per-flight shift), nominal (0), high (+sigma).
function bandsFor(rows, sigmaMin, sectorOrPairId, isPair) {
    const lowKey = isPair ? 'pair_load_low' : 'load_low';
    const highKey = isPair ? 'pair_load_high' : 'load_high';
    const lowRows = (window._mc?.[lowKey] || {})[sectorOrPairId] || [];
    const highRows = (window._mc?.[highKey] || {})[sectorOrPairId] || [];
    const lowByBin  = Object.fromEntries(lowRows.map(r => [r.bin_minute, r.count]));
    const highByBin = Object.fromEntries(highRows.map(r => [r.bin_minute, r.count]));
    const allBins = new Set([
        ...rows.map(r => r.bin_minute),
        ...lowRows.map(r => r.bin_minute),
        ...highRows.map(r => r.bin_minute),
    ]);
    const sorted = [...allBins].sort((a, b) => a - b);
    return sorted.map(b => {
        const nominalRow = rows.find(r => r.bin_minute === b);
        const c  = nominalRow ? nominalRow.count : 0;
        const lo = lowByBin[b]  ?? 0;
        const hi = highByBin[b] ?? 0;
        return {
            bin_minute: b,
            count: c,
            lo: Math.min(lo, c, hi),
            hi: Math.max(lo, c, hi),
        };
    });
}"""

for f in ['26e-1.html', '26e-2.html', '26e-3.html']:
    lead = int(f.split('-')[1].split('.')[0])
    sigma_grid = GRID_KT[lead]
    path = os.path.join(DIR, f)
    with open(path, 'r', encoding='utf-8') as fp:
        text = fp.read()

    # 1. Replace per-sector calibration block (handles both σ and sigma comments)
    pat = r"// Per-sector \S+ calibration:.*?window\._sectorSigma = sectorSigma;"
    if not re.search(pat, text, re.DOTALL):
        print(f'  {f}: per-sector cal not found, skipping')
        continue
    text = re.sub(pat, PRELOAD_BLOCK.replace('__SIGMA_GRID_KT__', str(sigma_grid)).strip(),
                  text, count=1, flags=re.DOTALL)

    # 2. Replace per-flight Gaussian smoothing fn with MC-based bandsFor
    pat2 = r'// Per-flight independent jitter model\..*?^\}'
    if not re.search(pat2, text, re.DOTALL | re.MULTILINE):
        print(f'  {f}: Gaussian bandsFor not found')
        continue
    text = re.sub(pat2, BANDSFOR_FN, text, count=1, flags=re.DOTALL | re.MULTILINE)

    # 3. Update drawChart caller
    old_call = 'const sigmaForThis = window._sectorSigma?.[sectorId] ?? SIGMA_MIN;\n        const banded = bandsFor(rows, sigmaForThis);'
    new_call = 'const isPairId = !!(window._mc?.pair_load?.[sectorId]);\n        const banded = bandsFor(rows, SIGMA_MIN, sectorId, isPairId);'
    if old_call in text:
        text = text.replace(old_call, new_call, 1)

    # 4. Update meta line text
    old_meta = 'lens applies ±${SIGMA_MIN} min ETA-shift envelope'
    new_meta = ('per-flight MC at sigma_grid=' + str(sigma_grid) +
                ' kt -> mean ±${(window._mc?.meanSigmaMin ?? 0).toFixed(1)} min ETA shift')
    text = text.replace(old_meta, new_meta)

    # 5. Pair card label
    old_label = 'D-${LEAD_DAYS} lens · ±${(window._sectorSigma?.[p.id] ?? SIGMA_MIN).toFixed(1)}m'
    new_label = 'D-${LEAD_DAYS} MC bands'
    text = text.replace(old_label, new_label)

    with open(path, 'w', encoding='utf-8', newline='') as fp:
        fp.write(text)
    print(f'  {f}: per-flight MC injected (sigma_grid={sigma_grid} kt)')
