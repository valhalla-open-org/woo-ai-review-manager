# Dashboard Analytics Widgets — Design Spec

## Context

The WooCommerce AI Review Manager dashboard currently shows basic stat cards (total/positive/neutral/negative counts), a recent reviews list, top products table, quick actions section, and AI status box. It lacks trend visualization, email campaign metrics, and period-over-period comparisons — the key data a store owner needs to answer "are things improving?" and "is the email system working?"

### Target Users

WooCommerce store owners and managers who check the dashboard:
- **Daily** — quick scan for what happened and what needs attention
- **Weekly** — deeper look at trends and email campaign effectiveness

### Constraints

- WordPress admin context, existing design system with CSS custom properties
- No external JS chart libraries — CSS and inline SVG only
- Must respect the existing period filter (7d / 30d / 90d / all time)
- System font stack only (no external font loading)
- 1.5px full borders on cards (no thick border-left accents)

---

## Widget Inventory

Five widgets, laid out top-to-bottom in three rows:

| Row | Widget | Span | Purpose |
|-----|--------|------|---------|
| 1 | KPI Cards (x4) | Full width, 4-column grid | Daily pulse metrics with sparklines |
| 2L | Sentiment Breakdown | Half width (left) | Positive/neutral/negative distribution |
| 2R | Email Funnel | Half width (right) | Sent → Clicked → Reviewed conversion |
| 3L | Recent Reviews | Half width (left) | Latest reviews with sentiment + actions |
| 3R | Top Products | Half width (right) | Best-reviewed products with score bars |

### What Changes From Current Dashboard

| Current | New |
|---------|-----|
| 4 stat cards (total, positive, neutral, negative) | 4 KPI cards with sparklines + deltas (total reviews, avg score, email→review rate, needs action) |
| Quick actions section (full row) | Slim toolbar buttons in page header |
| AI status box | Removed from dashboard (moves to Settings) |
| Setup checklist banner | Unchanged (conditional) |
| Failure widget banner | Unchanged (conditional) |
| No email metrics | Email Funnel widget |
| No sentiment distribution view | Sentiment Breakdown widget |
| No trend visualization | Sparklines in KPI cards |

---

## Widget 1: KPI Cards Row

Four cards in a responsive CSS grid (`repeat(auto-fill, minmax(200px, 1fr))`). Each card contains:

### Card Structure

```
┌─────────────────────────────────┐
│ LABEL              (11px, caps) │
│                                 │
│ 142          ╱╲  ╱╲            │
│              ╱  ╲╱  ╲  sparkline│
│ +12% vs prev period             │
└─────────────────────────────────┘
```

### Cards

| # | Label | Value | Delta Source | Sparkline |
|---|-------|-------|-------------|-----------|
| 1 | Total Reviews | Count of analyzed reviews in period | % change vs previous equal-length period | Review count per day/week |
| 2 | Avg Score | Average sentiment score (0.00–1.00) | Absolute change vs previous period | Avg score per day/week |
| 3 | Email → Review | (reviewed invitations / sent invitations) × 100 | Percentage point change vs previous period | Conversion rate per day/week |
| 4 | Needs Action | Count of reviews needing response | No sparkline | Tagged pills: "X negative" + "Y pending" |

### Sparkline Rendering

- Inline SVG, 64×28px viewBox
- Polyline stroke (1.5px) with gradient fill beneath (15% opacity at top → 0% at bottom)
- Stroke color: `--positive` when delta is positive, `--negative` when negative
- Auto-adapting granularity: daily data points for 7d/30d periods, weekly for 90d/all-time
- Data: one SQL query grouping by DATE(analyzed_at) or YEARWEEK(analyzed_at)

### "Needs Action" Card (Card 4)

No sparkline. Instead shows tagged pill badges:
- Negative reviews with `generated` or `approved` response status → red pill "X negative"
- Unanalyzed reviews (pending count from existing `wairm` localized data) → amber pill "Y pending"
- If both are zero, show a green "All clear" state

### Delta Calculation

"Previous period" = equal-length window before the current filter. For 30-day filter: current = last 30 days, previous = 31–60 days ago. Delta formula:
- Review count / email rate: `((current - previous) / previous) * 100` → show as "+12%" or "-5%"
- Avg score: absolute difference → show as "+0.05" or "-0.03"
- Color: `--positive` for improvement, `--negative` for decline, `--ink-muted` for no change

---

## Widget 2: Sentiment Breakdown

Left half of row 2. Three individual horizontal bars, one per sentiment category.

### Structure

```
┌──────────────────────────────────┐
│ Sentiment Breakdown              │
│                                  │
│ ● Positive              88 (62%)│
│ ████████████████████░░░░░░░░░░░ │
│                                  │
│ ● Neutral               34 (24%)│
│ ███████░░░░░░░░░░░░░░░░░░░░░░░ │
│                                  │
│ ● Negative              20 (14%)│
│ ████░░░░░░░░░░░░░░░░░░░░░░░░░░ │
└──────────────────────────────────┘
```

### Details

- Each row: color dot (8px circle) + label (12px, `--ink-soft`, semibold) on left, count + percentage on right
- Bar: 10px tall, `--surface-sunken` track, sentiment-colored fill
- Bar width = percentage of total (e.g., 62% of track width for positive)
- Colors: `--positive` / `--mixed` / `--negative`
- Data: reuses the existing overall stats query (already returns positive/neutral/negative counts)

---

## Widget 3: Email Funnel

Right half of row 2. Three progressive horizontal bars showing the invitation-to-review pipeline.

### Structure

```
┌──────────────────────────────────┐
│ Email Funnel                     │
│                                  │
│ Sent                         248 │
│ ████████████████████████████████ │
│                                  │
│ Clicked                156 (63%) │
│ ████████████████████░░░░░░░░░░░ │
│                                  │
│ Reviewed                84 (34%) │
│ ███████████░░░░░░░░░░░░░░░░░░░░ │
└──────────────────────────────────┘
```

### Details

- Three bars: Sent (100% width, full opacity), Clicked (proportional, 75% opacity), Reviewed (proportional, 50% opacity)
- Bar color: `--accent` (#6d3beb) with decreasing opacity per step
- Conversion percentages shown inline: Clicked shows % of Sent, Reviewed shows % of Sent
- Bar: 10px tall, `--surface-sunken` track, same border-radius as sentiment bars
- Data source: `wairm_review_invitations` table
  - Sent: `COUNT(*) WHERE status != 'pending'` (with date filter on `sent_at`)
  - Clicked: `COUNT(*) WHERE status IN ('clicked', 'reviewed')` (with date filter)
  - Reviewed: `COUNT(*) WHERE status = 'reviewed'` (with date filter)

---

## Widget 4: Recent Reviews

Left half of row 3. Unchanged from current layout but refined styling. Shows 10 most recent analyzed reviews.

### Each Review Row

- **Header line**: Product name (12px, semibold, `--ink`) | sentiment badge (pill style) | relative time (11px, `--ink-muted`)
- **Excerpt**: Review text trimmed to 30 words (12px, `--ink-muted`, line-height 1.5)
- **Respond link**: Shown only for negative reviews with pending/generated response status. "Respond →" in `--accent`, semibold. Links to Responses page filtered to that review.
- **Sent indicator**: Checkmark icon if response already sent (existing behavior)
- Rows separated by 1px `--border-light` lines

### Data

Existing `recent_reviews` query (joined sentiment + comments + posts). No changes needed.

---

## Widget 5: Top Products

Right half of row 3. Unchanged from current, showing top 5 products by review count.

### Table Columns

- **Product**: Product name (12px, `--ink`, medium weight)
- **Reviews**: Count, centered (12px, `--ink-soft`)
- **Avg Score**: Score bar (52px wide, 6px tall) + numeric value. Bar color by threshold: `--positive` (>0.65), `--mixed` (0.35–0.65), `--negative` (<0.35)

### Data

Existing `top_products` query. No changes needed.

---

## Page Header Toolbar

Quick actions move from a dedicated section to a slim row of buttons in the page header, beside the title.

```
AI Reviews                    [Responses (3)] [Invitations] [Insights] [Settings]
```

- Each button: 12px text, 1px `--border` border, `--radius-sm` radius, white background
- "Responses" button: highlighted in `--accent` text color when actionable count > 0, with a small pill badge showing the count
- Other buttons: `--ink-soft` text, neutral style
- "Analyze Unanalyzed Reviews" button: only appears when `pending_count > 0`, placed at the end of the toolbar row

---

## Responsive Behavior

| Breakpoint | Layout |
|------------|--------|
| > 960px | Full layout as described (4-col KPI, 2-col rows 2+3) |
| 600–960px | KPI cards: 2-column grid. Rows 2+3: stack to single column |
| < 600px | All single column. Toolbar buttons wrap. Period filter wraps |

---

## CSS Implementation Notes

### New CSS Classes

- `.wairm-kpi-card` — card container with sparkline layout
- `.wairm-kpi-sparkline` — SVG container (64×28px)
- `.wairm-kpi-delta` — delta text with positive/negative color
- `.wairm-kpi-delta.is-positive` / `.is-negative` — color variants
- `.wairm-kpi-pills` — flex container for action pills (card 4)
- `.wairm-sentiment-bars` — container for the three sentiment bars
- `.wairm-sentiment-row` — single bar row (label + bar + count)
- `.wairm-bar-track` — 10px tall track (reuse for both widgets)
- `.wairm-bar-fill` — colored fill div
- `.wairm-email-funnel` — funnel container
- `.wairm-funnel-step` — single funnel row
- `.wairm-toolbar` — header toolbar button row
- `.wairm-toolbar-btn` — individual toolbar button
- `.wairm-toolbar-btn.has-badge` — button with count badge

### Existing Classes Retained

- `.wairm-stats-grid` → renamed/repurposed for KPI grid
- `.wairm-two-column` → reused for rows 2 and 3
- `.wairm-recent-reviews`, `.wairm-review-card` → unchanged
- `.wairm-score-bar-track`, `.wairm-score-bar-fill` → unchanged for top products
- `.wairm-period-filter` → unchanged
- `.wairm-setup-checklist`, `.wairm-failure-widget` → unchanged
- `.sentiment-badge` → unchanged

### Animation

- KPI cards: staggered entrance animation (existing `wairm-card-enter`, 0.06s delay between cards)
- Bar fills: `width` transition 0.4s ease-out on load (CSS transition, triggered by adding a class after DOM ready)
- Sparkline: no animation (renders immediately)

---

## Data Queries

### New Queries Required

**1. Sparkline data** (one query, returns daily or weekly aggregates):
```sql
SELECT
  DATE(s.analyzed_at) as period_date,
  COUNT(*) as review_count,
  AVG(s.score) as avg_score
FROM {$table} s
WHERE s.analyzed_at >= %s
GROUP BY DATE(s.analyzed_at)
ORDER BY period_date ASC
```
For weekly granularity (90d+), replace `DATE()` with `YEARWEEK()`.

**2. Previous period stats** (for delta calculation):
```sql
SELECT COUNT(*) as total_reviews, AVG(score) as avg_score
FROM {$table}
WHERE analyzed_at >= %s AND analyzed_at < %s
```

**3. Email funnel counts**:
```sql
SELECT
  COUNT(CASE WHEN status IN ('sent','clicked','reviewed','expired') THEN 1 END) as sent,
  COUNT(CASE WHEN status IN ('clicked','reviewed') THEN 1 END) as clicked,
  COUNT(CASE WHEN status = 'reviewed' THEN 1 END) as reviewed
FROM {$invitations_table}
WHERE sent_at >= %s
```

**4. Email conversion sparkline**:
```sql
SELECT
  DATE(i.sent_at) as period_date,
  COUNT(CASE WHEN i.status = 'reviewed' THEN 1 END) * 100.0 / COUNT(*) as conversion_rate
FROM {$invitations_table} i
WHERE i.status != 'pending' AND i.sent_at >= %s
GROUP BY DATE(i.sent_at)
ORDER BY period_date ASC
```

### Existing Queries (No Changes)

- Overall sentiment stats (total/positive/neutral/negative counts + avg score)
- Recent reviews (joined comments + sentiment + posts, LIMIT 10)
- Top products (grouped by product_id, ORDER BY review_count DESC, LIMIT 5)
- Actionable response count
- Failed email count

---

## Mockup Reference

Visual mockup available at: `.superpowers/brainstorm/94973-1775239598/content/design-detail.html`
