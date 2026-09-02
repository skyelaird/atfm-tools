"""Generate SVG figures for the demand-modelling discussion paper.

Three figures:
  1. eastbound-skill.svg — per-flight ETA shift vs flight time from origin,
     multiple lead lines (T-24/T-72/T-120/T-168), eastbound waypoints
  2. westbound-skill.svg — same axes, westbound waypoints, longer flight times
  3. corpus-convergence.svg — RMSE estimate vs corpus age, showing CIs
     tightening as snapshots accumulate

Hand-rolled SVG: no plotting-library dependency, embeds cleanly in any PDF
exported from Typora.
"""
import math
import os

OUT_DIR = r'C:\Users\JoelMorin\OneDrive\Games\VATSIM\CTP\figures'
os.makedirs(OUT_DIR, exist_ok=True)

# ---------- shared style ----------
W, H = 880, 540
M = {'l': 70, 'r': 180, 't': 80, 'b': 80}
PLOT_W = W - M['l'] - M['r']
PLOT_H = H - M['t'] - M['b']

CSS = """
.bg     { fill: #ffffff; }
.frame  { fill: none; stroke: #222; stroke-width: 1; }
.grid   { stroke: #d8d8d8; stroke-width: 0.5; stroke-dasharray: 3 3; }
.axis-tick { stroke: #555; stroke-width: 0.7; }
.axis-label-x, .axis-label-y { font-family: -apple-system, system-ui, sans-serif; font-size: 11px; fill: #333; }
.axis-title  { font-family: -apple-system, system-ui, sans-serif; font-size: 12px; fill: #222; font-weight: 600; }
.title       { font-family: -apple-system, system-ui, sans-serif; font-size: 14px; fill: #111; font-weight: 600; }
.subtitle    { font-family: -apple-system, system-ui, sans-serif; font-size: 11px; fill: #666; }
.waypoint    { stroke: #999; stroke-width: 0.6; stroke-dasharray: 2 3; }
.waypoint-label { font-family: -apple-system, system-ui, sans-serif; font-size: 10px; fill: #555; }
.line        { fill: none; stroke-width: 2; stroke-linejoin: round; }
.line.anchor { stroke-width: 3.2; }
.legend-text { font-family: -apple-system, system-ui, sans-serif; font-size: 11px; fill: #222; }
.note        { font-family: -apple-system, system-ui, sans-serif; font-size: 10px; fill: #666; font-style: italic; }
.pbcs        { stroke: #c44; stroke-width: 1; stroke-dasharray: 5 3; }
.pbcs-label  { font-family: -apple-system, system-ui, sans-serif; font-size: 10px; fill: #c44; }
"""

# ---------- skill curve generator ----------
def skill_curve_svg(out_path, title, subtitle, waypoints, x_max=5.5, y_max=15,
                   leads=None, gs=480, show_pbcs=False):
    """waypoints: list of (hours, label). leads: list of (lead_label, X_kt, color, is_anchor)."""
    if leads is None:
        leads = [
            ('T-24 (day-before refinement)', 8,  '#5ee0ba', False),
            ('T-72 (planning anchor)',       17, '#1f6f8c', True),
        ]

    parts = []
    parts.append(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}">')
    parts.append(f'<style>{CSS}</style>')
    parts.append(f'<rect class="bg" x="0" y="0" width="{W}" height="{H}"/>')

    # Title
    parts.append(f'<text class="title" x="{M["l"]}" y="22">{title}</text>')
    parts.append(f'<text class="subtitle" x="{M["l"]}" y="40">{subtitle}</text>')

    # Plot frame
    parts.append(f'<rect class="frame" x="{M["l"]}" y="{M["t"]}" width="{PLOT_W}" height="{PLOT_H}"/>')

    # Scales
    def xs(h): return M['l'] + (h / x_max) * PLOT_W
    def ys(m): return M['t'] + PLOT_H - (m / y_max) * PLOT_H

    # Y gridlines + ticks
    y_step = 5
    for v in range(0, int(y_max) + 1, y_step):
        y = ys(v)
        parts.append(f'<line class="grid" x1="{M["l"]}" y1="{y:.1f}" x2="{M["l"]+PLOT_W}" y2="{y:.1f}"/>')
        parts.append(f'<text class="axis-label-y" x="{M["l"]-6}" y="{y+4:.1f}" text-anchor="end">{v}</text>')

    # X gridlines at integer hours
    for h in range(0, int(x_max) + 1):
        x = xs(h)
        parts.append(f'<line class="grid" x1="{x:.1f}" y1="{M["t"]}" x2="{x:.1f}" y2="{M["t"]+PLOT_H}"/>')
        parts.append(f'<text class="axis-label-x" x="{x:.1f}" y="{M["t"]+PLOT_H+15}" text-anchor="middle">{h}</text>')

    # Axis titles
    parts.append(f'<text class="axis-title" x="{M["l"]+PLOT_W/2:.1f}" y="{H-12}" text-anchor="middle">Flight time from origin (hours)</text>')
    parts.append(f'<text class="axis-title" transform="rotate(-90 {M["l"]-44} {M["t"]+PLOT_H/2:.1f})" x="{M["l"]-44}" y="{M["t"]+PLOT_H/2:.1f}" text-anchor="middle">Possible time error (minutes)</text>')

    # Waypoint vertical guides + labels (staggered when close together)
    last_x_at_top = -999
    last_x_at_bottom = -999
    for i, (h, label) in enumerate(waypoints):
        x = xs(h)
        parts.append(f'<line class="waypoint" x1="{x:.1f}" y1="{M["t"]}" x2="{x:.1f}" y2="{M["t"]+PLOT_H}"/>')
        # Decide whether to put label at top or bottom based on proximity to others
        # Goal: alternate placement when waypoints are close; keep text apart by >=80px
        place_at_top = True
        if x - last_x_at_top < 80:
            place_at_top = False
        if not place_at_top and x - last_x_at_bottom < 80:
            # Both rows are crowded; force top with smaller offset
            place_at_top = True
        if place_at_top:
            parts.append(f'<text class="waypoint-label" x="{x:.1f}" y="{M["t"]-8}" text-anchor="middle">{label}</text>')
            last_x_at_top = x
        else:
            # Below the frame, above the X-axis tick labels
            parts.append(f'<text class="waypoint-label" x="{x:.1f}" y="{M["t"]+PLOT_H+30}" text-anchor="middle">{label}</text>')
            last_x_at_bottom = x

    # PBCS reference line at 5 min (per-flight envelope = PBCS spacing margin)
    if show_pbcs:
        y = ys(5)
        parts.append(f'<line class="pbcs" x1="{M["l"]}" y1="{y:.1f}" x2="{M["l"]+PLOT_W}" y2="{y:.1f}"/>')
        parts.append(f'<text class="pbcs-label" x="{M["l"]+PLOT_W-6}" y="{y-4:.1f}" text-anchor="end">PBCS 5-min</text>')

    # Skill lines
    legend_y = M['t'] + 16
    for label, X_kt, color, anchor in leads:
        # shift_min(h) = X * h * 60 / GS
        slope = X_kt * 60 / gs
        pts = []
        h = 0
        while h <= x_max + 0.001:
            v = slope * h
            if v > y_max: break
            pts.append((xs(h), ys(v)))
            h += 0.05
        path = ' '.join(f'{x:.1f},{y:.1f}' for x, y in pts)
        cls = 'line anchor' if anchor else 'line'
        parts.append(f'<polyline class="{cls}" points="{path}" stroke="{color}"/>')

        # Legend entry
        lx = M['l'] + PLOT_W + 14
        parts.append(f'<line x1="{lx}" y1="{legend_y}" x2="{lx+24}" y2="{legend_y}" stroke="{color}" stroke-width="{3 if anchor else 2}"/>')
        weight = '700' if anchor else '500'
        parts.append(f'<text class="legend-text" x="{lx+30}" y="{legend_y+4}" font-weight="{weight}">{label} ({X_kt} kt)</text>')
        legend_y += 22

    # Footer note
    parts.append(f'<text class="note" x="{M["l"]+PLOT_W+14}" y="{M["t"]+PLOT_H-30}">Per-flight uncertainty.</text>')
    parts.append(f'<text class="note" x="{M["l"]+PLOT_W+14}" y="{M["t"]+PLOT_H-16}">Fleet-aggregate at hourly</text>')
    parts.append(f'<text class="note" x="{M["l"]+PLOT_W+14}" y="{M["t"]+PLOT_H-2}">bins is √N smaller.</text>')

    parts.append('</svg>')
    with open(out_path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(parts))
    print(f'Wrote: {out_path}')


# ---------- corpus-convergence figure ----------
def convergence_svg(out_path):
    """Show RMSE estimate at each lead as the spring sampling window progresses."""
    title = 'Wind error estimates — increasing confidence through the spring sampling window'
    subtitle = 'Estimates firm up as samples are collected; window capped at 30 days to keep the season consistent.'

    # Anchor values from current verifier (n=25 samples at day 11):
    # T-24 → 8.3 kt, T-72 → 17.5 kt, T-168 → 36 kt
    # CI envelope shrinks as ~1/√n where n = 4 samples/day × days
    leads = [
        ('T-24',  8.3,  '#5ee0ba', False),
        ('T-72',  17.5, '#1f6f8c', True),
        ('T-168', 36.0, '#c44',    False),
    ]

    # Domain — days into the 30-day spring window
    days_max = 30
    y_max = 50
    samples_per_day = 4
    today_day = 11
    today_samples = 25

    parts = []
    parts.append(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}">')
    parts.append(f'<style>{CSS}</style>')
    parts.append(f'<rect class="bg" x="0" y="0" width="{W}" height="{H}"/>')
    parts.append(f'<text class="title" x="{M["l"]}" y="22">{title}</text>')
    parts.append(f'<text class="subtitle" x="{M["l"]}" y="40">{subtitle}</text>')
    parts.append(f'<rect class="frame" x="{M["l"]}" y="{M["t"]}" width="{PLOT_W}" height="{PLOT_H}"/>')

    def xs(d): return M['l'] + (d / days_max) * PLOT_W
    def ys(v): return M['t'] + PLOT_H - (v / y_max) * PLOT_H

    # Y grid
    for v in range(0, y_max + 1, 10):
        y = ys(v)
        parts.append(f'<line class="grid" x1="{M["l"]}" y1="{y:.1f}" x2="{M["l"]+PLOT_W}" y2="{y:.1f}"/>')
        parts.append(f'<text class="axis-label-y" x="{M["l"]-6}" y="{y+4:.1f}" text-anchor="end">{v}</text>')

    # X grid at every 5 days
    for d in range(0, days_max + 1, 5):
        x = xs(d)
        parts.append(f'<line class="grid" x1="{x:.1f}" y1="{M["t"]}" x2="{x:.1f}" y2="{M["t"]+PLOT_H}"/>')
        parts.append(f'<text class="axis-label-x" x="{x:.1f}" y="{M["t"]+PLOT_H+15}" text-anchor="middle">{d}</text>')

    parts.append(f'<text class="axis-title" x="{M["l"]+PLOT_W/2:.1f}" y="{H-12}" text-anchor="middle">Days into spring sampling window (started 2026-04-25)</text>')
    parts.append(f'<text class="axis-title" transform="rotate(-90 {M["l"]-44} {M["t"]+PLOT_H/2:.1f})" x="{M["l"]-44}" y="{M["t"]+PLOT_H/2:.1f}" text-anchor="middle">Estimated wind error (kt)</text>')

    # "Today" marker
    x_today = xs(today_day)
    parts.append(f'<line class="waypoint" x1="{x_today:.1f}" y1="{M["t"]}" x2="{x_today:.1f}" y2="{M["t"]+PLOT_H}"/>')
    parts.append(f'<text class="waypoint-label" x="{x_today:.1f}" y="{M["t"]-8}" text-anchor="middle">today (day {today_day})</text>')

    # "Window ends — seasonal cutoff" marker
    x_end = xs(days_max)
    parts.append(f'<line class="waypoint" x1="{x_end:.1f}" y1="{M["t"]}" x2="{x_end:.1f}" y2="{M["t"]+PLOT_H}"/>')
    parts.append(f'<text class="waypoint-label" x="{x_end-4:.1f}" y="{M["t"]-8}" text-anchor="end">window ends (seasonal cutoff)</text>')

    legend_y = M['t'] + 16
    for label, asymptote, color, anchor in leads:
        # CI half-width approx 1/sqrt(samples), centred on asymptote.
        # samples = days × samples_per_day. Plot vs days on X axis.
        ci_pts_upper = []
        ci_pts_lower = []
        line_pts = []
        d = 0.5  # half-day starting point so we don't divide by zero
        while d <= days_max + 0.001:
            n = max(1, d * samples_per_day)
            # CI half-width: ~4 kt at n=1, ~0.5 kt at n=120
            half = 4.0 / math.sqrt(n)
            est = asymptote
            line_pts.append((xs(d), ys(est)))
            ci_pts_upper.append((xs(d), ys(est + half)))
            ci_pts_lower.append((xs(d), ys(est - half)))
            d += 0.25
        # Build CI polygon
        poly = ' '.join(f'{x:.1f},{y:.1f}' for x, y in ci_pts_upper)
        poly += ' ' + ' '.join(f'{x:.1f},{y:.1f}' for x, y in reversed(ci_pts_lower))
        parts.append(f'<polygon points="{poly}" fill="{color}" fill-opacity="0.18" stroke="none"/>')

        # Center line
        path = ' '.join(f'{x:.1f},{y:.1f}' for x, y in line_pts)
        sw = 3.2 if anchor else 2
        parts.append(f'<polyline points="{path}" fill="none" stroke="{color}" stroke-width="{sw}" stroke-linejoin="round"/>')

        lx = M['l'] + PLOT_W + 14
        parts.append(f'<line x1="{lx}" y1="{legend_y}" x2="{lx+24}" y2="{legend_y}" stroke="{color}" stroke-width="{3 if anchor else 2}"/>')
        weight = '700' if anchor else '500'
        parts.append(f'<text class="legend-text" x="{lx+30}" y="{legend_y+4}" font-weight="{weight}">{label}</text>')
        legend_y += 22

    parts.append(f'<text class="note" x="{M["l"]+PLOT_W+14}" y="{M["t"]+PLOT_H-58}">Shaded band:</text>')
    parts.append(f'<text class="note" x="{M["l"]+PLOT_W+14}" y="{M["t"]+PLOT_H-44}">confidence range</text>')
    parts.append(f'<text class="note" x="{M["l"]+PLOT_W+14}" y="{M["t"]+PLOT_H-30}">(narrows as more</text>')
    parts.append(f'<text class="note" x="{M["l"]+PLOT_W+14}" y="{M["t"]+PLOT_H-16}">samples are</text>')
    parts.append(f'<text class="note" x="{M["l"]+PLOT_W+14}" y="{M["t"]+PLOT_H-2}">added).</text>')

    # Footer note — climatological context (placed in the side-panel area, NOT below the X axis)
    side_x_foot = M['l'] + PLOT_W + 14
    parts.append(f'<text class="note" x="{side_x_foot}" y="{M["t"]+PLOT_H+18}" font-style="normal" fill="#1a8064" font-weight="600">Climatology bound:</text>')
    parts.append(f'<text class="note" x="{side_x_foot}" y="{M["t"]+PLOT_H+32}">spring regime ≈</text>')
    parts.append(f'<text class="note" x="{side_x_foot}" y="{M["t"]+PLOT_H+46}">April → mid-May.</text>')
    parts.append(f'<text class="note" x="{side_x_foot}" y="{M["t"]+PLOT_H+64}">Earlier risks winter</text>')
    parts.append(f'<text class="note" x="{side_x_foot}" y="{M["t"]+PLOT_H+78}">residue; later risks</text>')
    parts.append(f'<text class="note" x="{side_x_foot}" y="{M["t"]+PLOT_H+92}">summer transition.</text>')

    parts.append('</svg>')
    with open(out_path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(parts))
    print(f'Wrote: {out_path}')


# ---------- generate ----------
# Waypoints calibrated to a typical 26E flight (~8h trans-Atlantic).
# Median 26E flight time was 8.1h; p25-p75 = 7.3h-9.1h.
EASTBOUND_WAYPOINTS = [
    (1.0, 'domestic exit'),
    (2.5, 'OEP'),
    (5.0, '030W'),
    (7.0, 'OXP'),
    (7.5, 'EU entry'),
    (8.0, 'arrival'),
]

# Westbound mirrors the eastbound trans-Atlantic distance (~7-8h with headwinds).
WESTBOUND_WAYPOINTS = [
    (0.5, 'EU exit'),
    (1.0, 'OXP'),
    (3.5, '030W'),
    (6.0, 'OEP'),
    (6.5, 'ARTCC entry'),
    (7.5, 'descent'),
    (8.5, 'arrival'),
]

skill_curve_svg(
    os.path.join(OUT_DIR, 'eastbound-skill.svg'),
    title='Eastbound NAT — possible per-flight time error along a typical 26E profile',
    subtitle='Worked example: a typical 8-hour eastbound crossing (CYYC → EHAM class — median for 26E). The slope is the same for longer or shorter pairs; waypoints shift along the time axis.',
    waypoints=EASTBOUND_WAYPOINTS,
    x_max=8.5,
    y_max=20,
)

skill_curve_svg(
    os.path.join(OUT_DIR, 'westbound-skill.svg'),
    title='Westbound NAT — possible per-flight time error along a typical 27W profile',
    subtitle='Mirror of eastbound but with headwinds — typical 8.5-hour crossing. Same model, larger possible error near arrival because the flight is longer in time.',
    waypoints=WESTBOUND_WAYPOINTS,
    x_max=9.0,
    y_max=22,
)

convergence_svg(os.path.join(OUT_DIR, 'corpus-convergence.svg'))


# ---------- ROI / sweet-spot figure ----------
def sampling_roi_svg(out_path):
    """Diminishing returns: how the uncertainty in our wind-error estimate
    shrinks with days of sampling.

    Y-axis: how confident we are in our wind-error estimate, expressed as
    +/- kt around the estimate. Curve declines as 1/sqrt(samples) and never
    reaches zero — honestly showing the asymptotic nature.
    """
    title = 'Where does more wind data stop paying off?'
    subtitle = 'Confidence in our wind-error estimate improves quickly at first, then flattens. Most of the gain lands in the first ~10 days.'

    days_max = 30
    samples_per_day = 4

    parts = []
    parts.append(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}">')
    parts.append(f'<style>{CSS}</style>')
    parts.append(f'<rect class="bg" x="0" y="0" width="{W}" height="{H}"/>')
    parts.append(f'<text class="title" x="{M["l"]}" y="22">{title}</text>')
    parts.append(f'<text class="subtitle" x="{M["l"]}" y="40">{subtitle}</text>')
    parts.append(f'<rect class="frame" x="{M["l"]}" y="{M["t"]}" width="{PLOT_W}" height="{PLOT_H}"/>')

    # Y axis: +/- kt on our estimate, 0 to 2.5 kt
    y_max_kt = 2.5

    def xs(d): return M['l'] + (d / days_max) * PLOT_W
    def ys(kt): return M['t'] + PLOT_H - (kt / y_max_kt) * PLOT_H

    # Y grid every 0.5 kt
    v = 0.0
    while v <= y_max_kt + 0.001:
        y = ys(v)
        parts.append(f'<line class="grid" x1="{M["l"]}" y1="{y:.1f}" x2="{M["l"]+PLOT_W}" y2="{y:.1f}"/>')
        parts.append(f'<text class="axis-label-y" x="{M["l"]-6}" y="{y+4:.1f}" text-anchor="end">±{v:.1f}</text>')
        v += 0.5

    # X grid every 5 days
    for d in range(0, days_max + 1, 5):
        x = xs(d)
        parts.append(f'<line class="grid" x1="{x:.1f}" y1="{M["t"]}" x2="{x:.1f}" y2="{M["t"]+PLOT_H}"/>')
        parts.append(f'<text class="axis-label-x" x="{x:.1f}" y="{M["t"]+PLOT_H+15}" text-anchor="middle">{d}</text>')

    parts.append(f'<text class="axis-title" x="{M["l"]+PLOT_W/2:.1f}" y="{H-12}" text-anchor="middle">Days into spring sampling window (started 2026-04-25)</text>')
    parts.append(f'<text class="axis-title" transform="rotate(-90 {M["l"]-44} {M["t"]+PLOT_H/2:.1f})" x="{M["l"]-44}" y="{M["t"]+PLOT_H/2:.1f}" text-anchor="middle">Confidence range on our wind-error estimate (± kt)</text>')

    # Sweet-spot shaded zone (day 7 to day 15)
    sweet_x0 = xs(7)
    sweet_x1 = xs(15)
    parts.append(f'<rect x="{sweet_x0:.1f}" y="{M["t"]}" width="{sweet_x1-sweet_x0:.1f}" height="{PLOT_H}" fill="#5ee0ba" fill-opacity="0.12" stroke="none"/>')
    parts.append(f'<text class="waypoint-label" x="{(sweet_x0+sweet_x1)/2:.1f}" y="{M["t"]+PLOT_H+30:.1f}" text-anchor="middle" font-weight="700" fill="#1a8064">sweet spot</text>')

    # CI half-width: h(d) = h0 / sqrt(samples), with h0 ≈ 4 kt at n=1
    # samples = max(1, d × samples_per_day)
    h0 = 4.0

    def half_width_kt(d):
        n = max(1.0, d * samples_per_day)
        return h0 / math.sqrt(n)

    # Curve points
    pts = []
    d = 0.25
    while d <= days_max + 0.001:
        pts.append((xs(d), ys(min(half_width_kt(d), y_max_kt))))
        d += 0.1

    path = ' '.join(f'{x:.1f},{y:.1f}' for x, y in pts)
    parts.append(f'<polyline points="{path}" fill="none" stroke="#1f6f8c" stroke-width="3" stroke-linejoin="round"/>')

    # Annotation markers at key thresholds
    marker_specs = [
        (7,  -10, +8),   # above + right
        (11, -10, +8),
        (30, -10, -8),   # above + left (right edge)
    ]
    for d_mark, dy, dx in marker_specs:
        kt = half_width_kt(d_mark)
        x = xs(d_mark)
        y = ys(kt)
        parts.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="3.5" fill="#1f6f8c" stroke="white" stroke-width="1.2"/>')
        anchor = 'end' if dx < 0 else 'start'
        parts.append(f'<text class="waypoint-label" x="{x+dx:.1f}" y="{y+dy:.1f}" text-anchor="{anchor}" font-weight="600" fill="#1f6f8c">day {d_mark}: ±{kt:.2f} kt</text>')

    # Asymptote note — the curve approaches but never reaches zero
    asymptote_y = ys(0.05)
    parts.append(f'<line x1="{M["l"]}" y1="{asymptote_y:.1f}" x2="{M["l"]+PLOT_W}" y2="{asymptote_y:.1f}" stroke="#999" stroke-width="0.6" stroke-dasharray="2 4"/>')
    parts.append(f'<text class="note" x="{M["l"]+PLOT_W-6:.1f}" y="{asymptote_y-4:.1f}" text-anchor="end">tends towards zero</text>')

    # Side-bar interpretation
    side_x = M['l'] + PLOT_W + 14
    parts.append(f'<text class="legend-text" x="{side_x}" y="{M["t"]+18}" font-weight="700">Read this as:</text>')
    parts.append(f'<text class="note" x="{side_x}" y="{M["t"]+38}">Confidence range on</text>')
    parts.append(f'<text class="note" x="{side_x}" y="{M["t"]+52}">our wind-error</text>')
    parts.append(f'<text class="note" x="{side_x}" y="{M["t"]+66}">estimate, in ± kt.</text>')
    parts.append(f'<text class="note" x="{side_x}" y="{M["t"]+90}">Day 7: ±0.76 kt</text>')
    parts.append(f'<text class="note" x="{side_x}" y="{M["t"]+104}">Day 15: ±0.52 kt</text>')
    parts.append(f'<text class="note" x="{side_x}" y="{M["t"]+118}">Day 30: ±0.37 kt</text>')
    parts.append(f'<text class="note" x="{side_x}" y="{M["t"]+142}">Halving the range</text>')
    parts.append(f'<text class="note" x="{side_x}" y="{M["t"]+156}">requires 4× the data.</text>')
    parts.append(f'<text class="note" x="{side_x}" y="{M["t"]+184}" font-style="normal" fill="#1a8064" font-weight="600">Past the sweet spot,</text>')
    parts.append(f'<text class="note" x="{side_x}" y="{M["t"]+198}" font-style="normal" fill="#1a8064" font-weight="600">further sampling pays</text>')
    parts.append(f'<text class="note" x="{side_x}" y="{M["t"]+212}" font-style="normal" fill="#1a8064" font-weight="600">in fractions of a knot.</text>')

    parts.append('</svg>')
    with open(out_path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(parts))
    print(f'Wrote: {out_path}')


sampling_roi_svg(os.path.join(OUT_DIR, 'sampling-roi.svg'))

print()
print(f'Four figures saved under {OUT_DIR}')
