# Dashboard Analytics Widgets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the current dashboard with enriched KPI cards (sparklines + deltas), sentiment breakdown bars, email funnel widget, and full accessibility support.

**Architecture:** The dashboard is a single PHP class (`Dashboard_Page`) that queries data and renders HTML inline. New widgets are added as private render methods. Sparklines are inline SVGs generated server-side in PHP. New CSS classes are appended to the existing `admin.css`. The existing `dashboard.js` is extended with sparkline tooltip interactivity.

**Tech Stack:** PHP 8.0+, WordPress/WooCommerce APIs, CSS custom properties, inline SVG, vanilla JS

**Spec:** `docs/superpowers/specs/2026-04-03-dashboard-analytics-widgets-design.md`

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `includes/admin/class-dashboard-page.php` | Modify | Add new queries (sparkline data, previous period, email funnel), replace `render_page()` output with new widget layout, add private render helpers |
| `assets/css/admin.css` | Modify | Add KPI card, sentiment bars, email funnel, toolbar, empty state, focus, and reduced-motion styles |
| `assets/js/dashboard.js` | Modify | Add sparkline tooltip interactivity and bar fill animation trigger |

No new files are created. All changes are to existing files.

---

### Task 1: Add New Data Queries to Dashboard_Page

**Files:**
- Modify: `includes/admin/class-dashboard-page.php` (inside `render_page()`, after line 233)

This task adds the 4 new SQL queries needed by the new widgets: sparkline trend data, previous period stats, email funnel counts, and negative/pending action counts. No HTML changes yet.

- [ ] **Step 1: Add sparkline data query**

Add this method to `Dashboard_Page`, after the `get_setup_checklist()` method (after line 143):

```php
/**
 * Get sparkline trend data for KPI cards.
 *
 * @param string $period Current period filter value.
 * @return array{reviews: array, scores: array, conversions: array}
 */
private function get_sparkline_data( string $period ): array {
    global $wpdb;

    $sentiment_table   = $wpdb->prefix . 'wairm_review_sentiment';
    $invitations_table = $wpdb->prefix . 'wairm_review_invitations';

    // Determine date range and grouping.
    $use_weekly = in_array( $period, [ '90', 'all' ], true );
    $group_expr = $use_weekly ? 'YEARWEEK(s.analyzed_at, 1)' : 'DATE(s.analyzed_at)';
    $inv_group  = $use_weekly ? 'YEARWEEK(i.sent_at, 1)' : 'DATE(i.sent_at)';

    $date_filter = '';
    $inv_date_filter = '';
    if ( 'all' !== $period ) {
        $cutoff          = gmdate( 'Y-m-d H:i:s', strtotime( "-{$period} days" ) );
        $date_filter     = $wpdb->prepare( ' AND s.analyzed_at >= %s', $cutoff );
        $inv_date_filter = $wpdb->prepare( ' AND i.sent_at >= %s', $cutoff );
    }

    // Review count + avg score per period.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $review_trends = $wpdb->get_results(
        "SELECT {$group_expr} as period_key,
                COUNT(*) as review_count,
                AVG(score) as avg_score
         FROM {$sentiment_table} s
         WHERE 1=1 {$date_filter}
         GROUP BY period_key
         ORDER BY period_key ASC"
    );

    // Email conversion rate per period.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $conversion_trends = $wpdb->get_results(
        "SELECT {$inv_group} as period_key,
                COUNT(*) as total_sent,
                SUM(CASE WHEN i.status = 'reviewed' THEN 1 ELSE 0 END) as reviewed
         FROM {$invitations_table} i
         WHERE i.status != 'pending' {$inv_date_filter}
         GROUP BY period_key
         ORDER BY period_key ASC"
    );

    return [
        'reviews'     => array_map( static fn( $r ) => (int) $r->review_count, $review_trends ),
        'scores'      => array_map( static fn( $r ) => round( (float) $r->avg_score, 2 ), $review_trends ),
        'conversions' => array_map( static function ( $r ) {
            return $r->total_sent > 0 ? round( ( $r->reviewed / $r->total_sent ) * 100, 1 ) : 0;
        }, $conversion_trends ),
    ];
}
```

- [ ] **Step 2: Add previous period stats query**

Add this method directly after `get_sparkline_data()`:

```php
/**
 * Get stats for the previous equivalent period (for delta calculation).
 *
 * @param string $period Current period filter value.
 * @return object{total_reviews: int, avg_score: float, conversion_rate: float}
 */
private function get_previous_period_stats( string $period ): object {
    global $wpdb;

    $sentiment_table   = $wpdb->prefix . 'wairm_review_sentiment';
    $invitations_table = $wpdb->prefix . 'wairm_review_invitations';

    $defaults = (object) [
        'total_reviews'   => 0,
        'avg_score'       => 0.0,
        'conversion_rate' => 0.0,
    ];

    if ( 'all' === $period ) {
        return $defaults;
    }

    $days      = (int) $period;
    $prev_end  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
    $prev_start = gmdate( 'Y-m-d H:i:s', strtotime( "-" . ( $days * 2 ) . " days" ) );

    $prev_sentiment = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(*) as total_reviews, AVG(score) as avg_score
             FROM {$sentiment_table}
             WHERE analyzed_at >= %s AND analyzed_at < %s",
            $prev_start,
            $prev_end
        )
    );

    $prev_email = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                COUNT(*) as total_sent,
                SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed
             FROM {$invitations_table}
             WHERE status != 'pending'
               AND sent_at >= %s AND sent_at < %s",
            $prev_start,
            $prev_end
        )
    );

    return (object) [
        'total_reviews'   => (int) ( $prev_sentiment->total_reviews ?? 0 ),
        'avg_score'       => round( (float) ( $prev_sentiment->avg_score ?? 0 ), 2 ),
        'conversion_rate' => ( $prev_email && $prev_email->total_sent > 0 )
            ? round( ( $prev_email->reviewed / $prev_email->total_sent ) * 100, 1 )
            : 0.0,
    ];
}
```

- [ ] **Step 3: Add email funnel counts query**

Add this method directly after `get_previous_period_stats()`:

```php
/**
 * Get email funnel counts for the current period.
 *
 * @param string $date_where SQL WHERE fragment for date filtering (on column `i.sent_at`).
 * @return object{sent: int, clicked: int, reviewed: int}
 */
private function get_email_funnel( string $date_where ): object {
    global $wpdb;

    $invitations_table = $wpdb->prefix . 'wairm_review_invitations';

    // Rewrite date_where to use `i.sent_at` instead of `s.analyzed_at`.
    $inv_date_where = str_replace( 's.analyzed_at', 'i.sent_at', $date_where );

    $row = $wpdb->get_row(
        "SELECT
            COUNT(CASE WHEN i.status IN ('sent','clicked','reviewed','expired') THEN 1 END) as sent,
            COUNT(CASE WHEN i.status IN ('clicked','reviewed') THEN 1 END) as clicked,
            COUNT(CASE WHEN i.status = 'reviewed' THEN 1 END) as reviewed
         FROM {$invitations_table} i
         WHERE 1=1 {$inv_date_where}"
    );

    return (object) [
        'sent'     => (int) ( $row->sent ?? 0 ),
        'clicked'  => (int) ( $row->clicked ?? 0 ),
        'reviewed' => (int) ( $row->reviewed ?? 0 ),
    ];
}
```

- [ ] **Step 4: Wire up the new queries in render_page()**

In `render_page()`, after the `$failed_emails` query (after line 198), add these calls:

```php
// New dashboard data.
$sparkline_data = $this->get_sparkline_data( $period );
$prev_stats     = $this->get_previous_period_stats( $period );
$email_funnel   = $this->get_email_funnel( $date_where );

// Current period conversion rate.
$current_conversion = $email_funnel->sent > 0
    ? round( ( $email_funnel->reviewed / $email_funnel->sent ) * 100, 1 )
    : 0.0;

// Negative reviews needing response.
$negative_needing_response = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table}
         WHERE sentiment = %s
           AND ai_response_suggestion IS NOT NULL
           AND ai_response_status IN (%s, %s)",
        'negative',
        'generated',
        'approved'
    )
);
```

- [ ] **Step 5: Update localized script data**

Update the `localize_dashboard_data()` method signature and body to pass sparkline data to JS. Replace the entire method (lines 68–86):

```php
/**
 * Localize dashboard script with chart data.
 *
 * @param object $stats            Overall sentiment stats.
 * @param int    $pending_count    Unanalyzed review count.
 * @param int    $actionable_responses Actionable response count.
 * @param array  $sparkline_data   Sparkline trend arrays.
 */
private function localize_dashboard_data( object $stats, int $pending_count, int $actionable_responses, array $sparkline_data ): void {
    wp_localize_script(
        'wairm-dashboard',
        'wairm',
        [
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'wairm_dashboard' ),
            'pending_count'   => $pending_count,
            'sparkline_data'  => $sparkline_data,
            'i18n'            => [
                'analyze_button'  => __( 'Analyze Old Reviews', 'woo-ai-review-manager' ),
                'analyzing'       => __( 'Analyzing...', 'woo-ai-review-manager' ),
                'batch_progress'  => __( 'Analyzed %1$d of %2$d...', 'woo-ai-review-manager' ),
                'complete'        => __( 'All done! Reloading...', 'woo-ai-review-manager' ),
                'nothing'         => __( 'No unanalyzed reviews found.', 'woo-ai-review-manager' ),
                'error'           => __( 'An error occurred. Please try again.', 'woo-ai-review-manager' ),
            ],
        ]
    );
}
```

Update the call in `render_page()` (line 200) from:
```php
$this->localize_dashboard_data( $stats, $pending_count, $actionable_responses );
```
to:
```php
$this->localize_dashboard_data( $stats, $pending_count, $actionable_responses, $sparkline_data );
```

- [ ] **Step 6: Commit**

```bash
git add includes/admin/class-dashboard-page.php
git commit -m "feat(dashboard): add sparkline, previous period, and email funnel queries"
```

---

### Task 2: Add New CSS Styles

**Files:**
- Modify: `assets/css/admin.css` (append after existing dashboard styles, before the "Shared - Sentiment Badge" section at line 208)

This task adds all new CSS classes for KPI cards, sentiment bars, email funnel, toolbar, empty states, focus rings, and reduced motion. No PHP/HTML changes.

- [ ] **Step 1: Add KPI card styles**

Insert the following CSS block before the `/* Shared – Sentiment Badge */` comment (line 208 in `admin.css`). This replaces the old stat card colored borders with a new layout:

```css
/* -------------------------------------------------------
   Dashboard – KPI Cards (replaces stat-card color variants)
   ------------------------------------------------------- */
.wairm-kpi-card {
	background: var(--surface);
	border: 1.5px solid var(--border);
	border-radius: var(--radius);
	padding: 18px 16px;
	box-shadow: var(--shadow-sm);
	transition: box-shadow 0.2s ease, transform 0.2s ease;
	animation: wairm-card-enter 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
}

.wairm-kpi-card:nth-child(1) { animation-delay: 0s; }
.wairm-kpi-card:nth-child(2) { animation-delay: 0.06s; }
.wairm-kpi-card:nth-child(3) { animation-delay: 0.12s; }
.wairm-kpi-card:nth-child(4) { animation-delay: 0.18s; }

.wairm-kpi-card:hover {
	box-shadow: var(--shadow-md);
	transform: translateY(-1px);
}

.wairm-kpi-card .kpi-label {
	font-family: var(--font-body);
	font-size: 11px;
	font-weight: 500;
	color: var(--ink-muted);
	text-transform: uppercase;
	letter-spacing: 0.06em;
	margin-bottom: 8px;
}

.wairm-kpi-body {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
}

.wairm-kpi-card .kpi-value {
	font-family: var(--font-display);
	font-size: 32px;
	font-weight: 400;
	line-height: 1;
	letter-spacing: -0.02em;
	color: var(--ink);
	font-variant-numeric: tabular-nums;
}

.wairm-kpi-delta {
	font-family: var(--font-body);
	font-size: 11px;
	font-weight: 600;
	margin-top: 4px;
}

.wairm-kpi-delta.is-positive { color: var(--positive); }
.wairm-kpi-delta.is-negative { color: var(--negative); }
.wairm-kpi-delta.is-neutral  { color: var(--ink-muted); }

.wairm-kpi-delta .delta-context {
	font-weight: 400;
	color: var(--ink-muted);
}

.wairm-kpi-sparkline {
	flex-shrink: 0;
	margin-bottom: 4px;
}

.wairm-kpi-sparkline svg {
	display: block;
}

/* Action pills for "Needs Action" card */
.wairm-kpi-pills {
	display: flex;
	gap: 6px;
	margin-top: 6px;
	flex-wrap: wrap;
}

.wairm-kpi-pill {
	font-family: var(--font-body);
	font-size: 10px;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 4px;
}

.wairm-kpi-pill.pill-negative {
	background: var(--negative-bg);
	color: var(--negative);
	border: 1px solid var(--negative-border);
}

.wairm-kpi-pill.pill-pending {
	background: var(--mixed-bg);
	color: var(--mixed);
	border: 1px solid var(--mixed-border);
}

.wairm-kpi-pill.pill-clear {
	background: var(--positive-bg);
	color: var(--positive);
	border: 1px solid var(--positive-border);
}

.wairm-kpi-card.needs-action .kpi-value {
	color: var(--negative);
}

.wairm-kpi-card.all-clear .kpi-value {
	color: var(--positive);
}
```

- [ ] **Step 2: Add sentiment bars and email funnel styles**

Insert directly after the KPI card block:

```css
/* -------------------------------------------------------
   Dashboard – Sentiment Breakdown Bars
   ------------------------------------------------------- */
.wairm-widget-card {
	background: var(--surface);
	border: 1.5px solid var(--border);
	border-radius: var(--radius);
	padding: 20px;
	box-shadow: var(--shadow-sm);
}

.wairm-widget-card .widget-title {
	font-family: var(--font-display);
	font-size: 15px;
	color: var(--ink);
	margin: 0 0 16px;
}

.wairm-sentiment-row {
	margin-bottom: 12px;
}

.wairm-sentiment-row:last-child {
	margin-bottom: 0;
}

.wairm-sentiment-row .bar-label {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-family: var(--font-body);
	font-size: 12px;
	margin-bottom: 5px;
}

.wairm-sentiment-row .bar-label-left {
	display: flex;
	align-items: center;
	gap: 6px;
}

.wairm-sentiment-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	display: inline-block;
	flex-shrink: 0;
}

.wairm-sentiment-dot.dot-positive { background: var(--positive); }
.wairm-sentiment-dot.dot-neutral  { background: var(--mixed); }
.wairm-sentiment-dot.dot-negative { background: var(--negative); }

.wairm-sentiment-row .bar-label-left span:last-child {
	font-weight: 600;
	color: var(--ink-soft);
}

.wairm-sentiment-row .bar-label-right {
	font-weight: 600;
	color: var(--ink);
}

.wairm-sentiment-row .bar-label-right .bar-pct {
	font-weight: 400;
	color: var(--ink-muted);
}

.wairm-bar-track {
	height: 10px;
	background: var(--surface-sunken);
	border-radius: 5px;
	overflow: hidden;
}

.wairm-bar-fill {
	height: 10px;
	border-radius: 5px;
	width: 0;
	transition: width 0.4s ease-out;
}

.wairm-bar-fill.fill-positive { background: var(--positive); }
.wairm-bar-fill.fill-neutral  { background: var(--mixed); }
.wairm-bar-fill.fill-negative { background: var(--negative); }

/* -------------------------------------------------------
   Dashboard – Email Funnel
   ------------------------------------------------------- */
.wairm-funnel-step {
	margin-bottom: 10px;
}

.wairm-funnel-step:last-child {
	margin-bottom: 0;
}

.wairm-funnel-step .funnel-label {
	display: flex;
	justify-content: space-between;
	font-family: var(--font-body);
	font-size: 12px;
	margin-bottom: 4px;
}

.wairm-funnel-step .funnel-label-name {
	color: var(--ink-soft);
	font-weight: 500;
}

.wairm-funnel-step .funnel-label-value {
	color: var(--ink);
	font-weight: 600;
}

.wairm-funnel-step .funnel-label-value .funnel-pct {
	color: var(--ink-muted);
	font-size: 11px;
	font-weight: 400;
}

.wairm-bar-fill.fill-accent {
	background: var(--accent);
}

.wairm-funnel-step:nth-child(2) .wairm-bar-fill { opacity: 0.75; }
.wairm-funnel-step:nth-child(3) .wairm-bar-fill { opacity: 0.50; }

.wairm-funnel-dropoff {
	font-family: var(--font-body);
	font-size: 10px;
	color: var(--ink-muted);
	text-align: right;
	padding: 2px 0 4px;
}
```

- [ ] **Step 3: Add toolbar, empty state, focus, and reduced-motion styles**

Insert directly after the email funnel block:

```css
/* -------------------------------------------------------
   Dashboard – Header Toolbar
   ------------------------------------------------------- */
.wairm-toolbar {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}

.wairm-toolbar-btn {
	font-family: var(--font-body);
	font-size: 12px;
	color: var(--ink-soft);
	text-decoration: none;
	padding: 5px 12px;
	border: 1px solid var(--border);
	border-radius: var(--radius-sm);
	background: var(--surface);
	cursor: pointer;
	transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.wairm-toolbar-btn:hover {
	background: var(--surface-raised);
	color: var(--ink);
}

.wairm-toolbar-btn:active {
	transform: scale(0.98);
}

.wairm-toolbar-btn.has-badge {
	color: var(--accent);
}

.wairm-toolbar-btn .toolbar-badge {
	display: inline-block;
	background: var(--accent);
	color: var(--surface);
	font-size: 10px;
	font-weight: 700;
	padding: 1px 6px;
	border-radius: 10px;
	margin-left: 4px;
	line-height: 1.4;
}

/* -------------------------------------------------------
   Dashboard – Mid Row (Sentiment + Funnel side by side)
   ------------------------------------------------------- */
.wairm-mid-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 14px;
	margin-bottom: 18px;
}

@media screen and (max-width: 960px) {
	.wairm-mid-row {
		grid-template-columns: 1fr;
	}
}

/* -------------------------------------------------------
   Dashboard – Empty States
   ------------------------------------------------------- */
.wairm-widget-empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	text-align: center;
	padding: 24px 16px;
	min-height: 120px;
}

.wairm-widget-empty p {
	font-family: var(--font-body);
	font-size: 13px;
	color: var(--ink-muted);
	margin: 0;
	line-height: 1.5;
}

.wairm-widget-empty .empty-subtext {
	font-size: 12px;
	color: var(--ink-muted);
	margin-top: 4px;
}

.wairm-welcome-banner {
	background: var(--surface);
	border: 1.5px solid var(--border);
	border-radius: var(--radius);
	padding: 28px 24px;
	margin-bottom: 18px;
	box-shadow: var(--shadow-sm);
}

.wairm-welcome-banner h2 {
	font-family: var(--font-display);
	font-size: 20px;
	font-weight: 400;
	color: var(--ink);
	margin: 0 0 8px;
}

.wairm-welcome-banner p {
	font-family: var(--font-body);
	font-size: 13px;
	color: var(--ink-soft);
	margin: 0 0 4px;
	line-height: 1.6;
}

.wairm-welcome-banner ol {
	font-family: var(--font-body);
	font-size: 13px;
	color: var(--ink-soft);
	margin: 8px 0 16px 20px;
	line-height: 1.8;
}

.wairm-welcome-actions {
	display: flex;
	gap: 8px;
}

/* -------------------------------------------------------
   Dashboard – Accessibility: Focus & Reduced Motion
   ------------------------------------------------------- */
.wairm-dashboard .wairm-toolbar-btn:focus-visible,
.wairm-dashboard .wairm-period-filter a:focus-visible,
.wairm-dashboard .wairm-review-card .button-small:focus-visible,
.wairm-dashboard .review-meta a:focus-visible,
.wairm-dashboard .wairm-kpi-card:focus-visible {
	outline: 2px solid var(--accent);
	outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
	.wairm-kpi-card {
		animation: none;
	}

	.wairm-bar-fill {
		transition: none;
	}

	.wairm-toolbar-btn,
	.wairm-period-filter a,
	.wairm-kpi-card,
	.wairm-review-card {
		transition: none;
	}
}

/* -------------------------------------------------------
   Dashboard – Sparkline Tooltip
   ------------------------------------------------------- */
.wairm-sparkline-tooltip {
	position: absolute;
	background: var(--ink);
	color: var(--surface);
	font-family: var(--font-body);
	font-size: 11px;
	font-weight: 500;
	padding: 4px 8px;
	border-radius: 4px;
	white-space: nowrap;
	pointer-events: none;
	z-index: 10;
	opacity: 0;
	transition: opacity 0.15s ease;
}

.wairm-sparkline-tooltip.is-visible {
	opacity: 1;
}

.wairm-kpi-sparkline {
	position: relative;
}
```

- [ ] **Step 4: Commit**

```bash
git add assets/css/admin.css
git commit -m "feat(dashboard): add CSS for KPI cards, sentiment bars, email funnel, toolbar, a11y"
```

---

### Task 3: Replace Dashboard HTML — Page Header and KPI Cards

**Files:**
- Modify: `includes/admin/class-dashboard-page.php` (replace the HTML output in `render_page()`)

This task replaces the page header, quick actions, and stat cards with the new toolbar and KPI cards. We add a private helper method to generate sparkline SVGs.

- [ ] **Step 1: Add sparkline SVG helper method**

Add this method after `get_email_funnel()`:

```php
/**
 * Generate an inline SVG sparkline from a data array.
 *
 * @param array<float|int> $data      Data points.
 * @param bool             $is_positive Whether the trend is positive (green) or negative (red).
 * @return string SVG markup.
 */
private function render_sparkline_svg( array $data, bool $is_positive ): string {
    if ( count( $data ) < 2 ) {
        return '';
    }

    $width  = 64;
    $height = 28;
    $color  = $is_positive ? 'var(--positive)' : 'var(--negative)';
    $count  = count( $data );
    $max    = max( $data );
    $min    = min( $data );
    $range  = $max - $min ?: 1;

    $points = [];
    for ( $i = 0; $i < $count; $i++ ) {
        $x = round( ( $i / ( $count - 1 ) ) * $width, 1 );
        $y = round( $height - ( ( $data[ $i ] - $min ) / $range ) * ( $height - 4 ) - 2, 1 );
        $points[] = "{$x},{$y}";
    }

    $polyline = implode( ' ', $points );
    // Build the fill path (area under the line).
    $fill_path = "M{$points[0]} " . implode( ' L', array_slice( $points, 1 ) ) . " L{$width},{$height} L0,{$height} Z";

    $gradient_id = 'sg' . wp_unique_id();

    return sprintf(
        '<svg width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" aria-hidden="true" focusable="false">
            <defs><linearGradient id="%3$s" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%%" stop-color="%4$s" stop-opacity="0.15"/>
                <stop offset="100%%" stop-color="%4$s" stop-opacity="0"/>
            </linearGradient></defs>
            <path d="%5$s" fill="url(#%3$s)"/>
            <polyline points="%6$s" fill="none" stroke="%4$s" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>',
        $width,
        $height,
        esc_attr( $gradient_id ),
        esc_attr( $color ),
        esc_attr( $fill_path ),
        esc_attr( $polyline )
    );
}
```

- [ ] **Step 2: Add delta formatting helper**

Add this method directly after `render_sparkline_svg()`:

```php
/**
 * Format a delta value for display.
 *
 * @param float  $current  Current value.
 * @param float  $previous Previous value.
 * @param string $format   'percent' for % change, 'absolute' for raw difference.
 * @param string $period   Current period ('all' = no delta shown).
 * @return array{html: string, is_positive: bool}
 */
private function format_delta( float $current, float $previous, string $format, string $period ): array {
    if ( 'all' === $period || 0.0 === $previous ) {
        return [
            'html'        => '<span class="wairm-kpi-delta is-neutral">&mdash;</span>',
            'is_positive' => true,
        ];
    }

    if ( 'percent' === $format ) {
        $change      = ( ( $current - $previous ) / $previous ) * 100;
        $display     = ( $change >= 0 ? '+' : '' ) . round( $change, 1 ) . '%';
    } else {
        $change  = $current - $previous;
        $display = ( $change >= 0 ? '+' : '' ) . number_format( $change, 2 );
    }

    $is_positive = $change >= 0;
    $arrow       = $is_positive ? '&#9650;' : '&#9660;';
    $class       = $is_positive ? 'is-positive' : 'is-negative';

    $html = sprintf(
        '<span class="wairm-kpi-delta %s">%s %s <span class="delta-context">%s</span></span>',
        esc_attr( $class ),
        $arrow,
        esc_html( $display ),
        esc_html__( 'vs prev period', 'woo-ai-review-manager' )
    );

    return [
        'html'        => $html,
        'is_positive' => $is_positive,
    ];
}
```

- [ ] **Step 3: Replace the page header, period filter, stats grid, and quick actions HTML**

In `render_page()`, replace everything from the opening `<div class="wrap wairm-dashboard">` through the closing `</div>` of the quick-actions section (lines 235–372) with the new header, toolbar, period filter, and KPI cards:

```php
		// Calculate deltas.
		$review_delta     = $this->format_delta( (float) ( $stats->total_reviews ?? 0 ), (float) $prev_stats->total_reviews, 'percent', $period );
		$score_delta      = $this->format_delta( (float) ( $stats->avg_score ?? 0 ), $prev_stats->avg_score, 'absolute', $period );
		$conversion_delta = $this->format_delta( $current_conversion, $prev_stats->conversion_rate, 'absolute', $period );

		$needs_action_count = $negative_needing_response + $pending_count;
		?>
		<div class="wrap wairm-dashboard">
			<div class="wairm-page-header">
				<h1><?php esc_html_e( 'AI Reviews', 'woo-ai-review-manager' ); ?></h1>
				<div class="wairm-toolbar">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-responses' ) ); ?>"
					   class="wairm-toolbar-btn <?php echo $actionable_responses > 0 ? 'has-badge' : ''; ?>">
						<?php esc_html_e( 'Responses', 'woo-ai-review-manager' ); ?>
						<?php if ( $actionable_responses > 0 ) : ?>
							<span class="toolbar-badge"><?php echo absint( $actionable_responses ); ?></span>
						<?php endif; ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-invitations' ) ); ?>" class="wairm-toolbar-btn">
						<?php esc_html_e( 'Invitations', 'woo-ai-review-manager' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-insights' ) ); ?>" class="wairm-toolbar-btn">
						<?php esc_html_e( 'Insights', 'woo-ai-review-manager' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-settings' ) ); ?>" class="wairm-toolbar-btn">
						<?php esc_html_e( 'Settings', 'woo-ai-review-manager' ); ?>
					</a>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wairm_export_csv&export_type=reviews' ), 'wairm_export_csv' ) ); ?>" class="wairm-toolbar-btn">
						<?php esc_html_e( 'Export CSV', 'woo-ai-review-manager' ); ?>
					</a>
					<?php if ( $pending_count > 0 ) : ?>
						<button class="wairm-toolbar-btn" id="wairm-analyze-old-reviews">
							<?php
							printf(
								/* translators: %d: number of unanalyzed reviews */
								esc_html__( 'Analyze %d Reviews', 'woo-ai-review-manager' ),
								$pending_count
							);
							?>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $checklist ) ) : ?>
			<div class="wairm-setup-checklist">
				<h3><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Setup Incomplete', 'woo-ai-review-manager' ); ?></h3>
				<p><?php esc_html_e( 'Complete these steps to get the most out of AI Review Manager:', 'woo-ai-review-manager' ); ?></p>
				<ul>
					<?php foreach ( $checklist as $item ) : ?>
						<li>
							<?php if ( ! empty( $item['url'] ) ) : ?>
								<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $item['label'] ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

			<?php if ( $failed_emails > 0 ) : ?>
			<div class="wairm-failure-widget">
				<span class="dashicons dashicons-warning"></span>
				<p>
					<?php
					printf(
						esc_html( _n(
							'%1$d email failed to send. %2$sView invitations%3$s to investigate.',
							'%1$d emails failed to send. %2$sView invitations%3$s to investigate.',
							$failed_emails,
							'woo-ai-review-manager'
						) ),
						$failed_emails,
						'<a href="' . esc_url( admin_url( 'admin.php?page=wairm-invitations' ) ) . '">',
						'</a>'
					);
					?>
				</p>
			</div>
			<?php endif; ?>

			<?php if ( $pending_count > 0 ) : ?>
			<div id="wairm-analyze-progress" class="wairm-progress-wrap" style="display: none;">
				<div class="wairm-progress-track">
					<div id="wairm-progress-bar" class="wairm-progress-fill"></div>
				</div>
				<p id="wairm-progress-text" class="wairm-progress-text"></p>
			</div>
			<?php endif; ?>

			<?php
			// First-run welcome banner: shown when zero reviews have ever been analyzed.
			$total_all_time = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			if ( 0 === $total_all_time ) :
			?>
			<div class="wairm-welcome-banner">
				<h2><?php esc_html_e( 'Welcome to AI Reviews', 'woo-ai-review-manager' ); ?></h2>
				<p><?php esc_html_e( 'Your dashboard will populate as reviews come in. To get started:', 'woo-ai-review-manager' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Verify your AI connector in Settings', 'woo-ai-review-manager' ); ?></li>
					<li><?php esc_html_e( 'Send your first review invitation', 'woo-ai-review-manager' ); ?></li>
				</ol>
				<div class="wairm-welcome-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-settings' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Go to Settings', 'woo-ai-review-manager' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-invitations' ) ); ?>" class="button">
						<?php esc_html_e( 'Send Invitations', 'woo-ai-review-manager' ); ?>
					</a>
				</div>
			</div>
			<?php endif; ?>

			<div class="wairm-period-filter" role="group" aria-label="<?php esc_attr_e( 'Filter by time period', 'woo-ai-review-manager' ); ?>">
				<?php
				$period_base = admin_url( 'admin.php?page=wairm-dashboard' );
				$periods     = [
					'7'   => __( 'Last 7 days', 'woo-ai-review-manager' ),
					'30'  => __( 'Last 30 days', 'woo-ai-review-manager' ),
					'90'  => __( 'Last 90 days', 'woo-ai-review-manager' ),
					'all' => __( 'All time', 'woo-ai-review-manager' ),
				];
				foreach ( $periods as $key => $label ) :
				?>
					<a href="<?php echo esc_url( add_query_arg( 'period', $key, $period_base ) ); ?>"
					   class="button <?php echo $period === $key ? 'button-primary' : ''; ?>"
					   <?php echo $period === $key ? 'aria-pressed="true"' : 'aria-pressed="false"'; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</div>

			<!-- KPI Cards Row -->
			<div class="wairm-stats-grid">
				<?php
				// Card 1: Total Reviews.
				$sparkline_reviews = $this->render_sparkline_svg( $sparkline_data['reviews'], $review_delta['is_positive'] );
				?>
				<div class="wairm-kpi-card" role="group"
				     aria-label="<?php printf( esc_attr__( 'Total Reviews: %d', 'woo-ai-review-manager' ), absint( $stats->total_reviews ?? 0 ) ); ?>">
					<div class="kpi-label"><?php esc_html_e( 'Total Reviews', 'woo-ai-review-manager' ); ?></div>
					<div class="wairm-kpi-body">
						<div>
							<div class="kpi-value"><?php echo absint( $stats->total_reviews ?? 0 ); ?></div>
							<?php echo $review_delta['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built with esc_html/esc_attr. ?>
						</div>
						<?php if ( $sparkline_reviews ) : ?>
							<div class="wairm-kpi-sparkline" aria-label="<?php esc_attr_e( 'Review count trend', 'woo-ai-review-manager' ); ?>">
								<?php echo $sparkline_reviews; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<?php
				// Card 2: Avg Score.
				$sparkline_scores = $this->render_sparkline_svg( $sparkline_data['scores'], $score_delta['is_positive'] );
				?>
				<div class="wairm-kpi-card" role="group"
				     aria-label="<?php printf( esc_attr__( 'Average Score: %s', 'woo-ai-review-manager' ), number_format( (float) ( $stats->avg_score ?? 0 ), 2 ) ); ?>">
					<div class="kpi-label"><?php esc_html_e( 'Avg Score', 'woo-ai-review-manager' ); ?></div>
					<div class="wairm-kpi-body">
						<div>
							<div class="kpi-value"><?php echo esc_html( number_format( (float) ( $stats->avg_score ?? 0 ), 2 ) ); ?></div>
							<?php echo $score_delta['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<?php if ( $sparkline_scores ) : ?>
							<div class="wairm-kpi-sparkline" aria-label="<?php esc_attr_e( 'Sentiment score trend', 'woo-ai-review-manager' ); ?>">
								<?php echo $sparkline_scores; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<?php
				// Card 3: Email → Review.
				$sparkline_conv = $this->render_sparkline_svg( $sparkline_data['conversions'], $conversion_delta['is_positive'] );
				?>
				<div class="wairm-kpi-card" role="group"
				     aria-label="<?php printf( esc_attr__( 'Email to Review conversion: %s%%', 'woo-ai-review-manager' ), $current_conversion ); ?>">
					<div class="kpi-label"><?php echo esc_html_x( 'Email &rarr; Review', 'dashboard KPI label', 'woo-ai-review-manager' ); ?></div>
					<div class="wairm-kpi-body">
						<div>
							<div class="kpi-value"><?php echo esc_html( $current_conversion . '%' ); ?></div>
							<?php echo $conversion_delta['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<?php if ( $sparkline_conv ) : ?>
							<div class="wairm-kpi-sparkline" aria-label="<?php esc_attr_e( 'Conversion rate trend', 'woo-ai-review-manager' ); ?>">
								<?php echo $sparkline_conv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<?php // Card 4: Needs Action. ?>
				<div class="wairm-kpi-card <?php echo $needs_action_count > 0 ? 'needs-action' : 'all-clear'; ?>" role="group"
				     aria-label="<?php printf( esc_attr__( 'Needs Action: %d items', 'woo-ai-review-manager' ), $needs_action_count ); ?>">
					<div class="kpi-label"><?php esc_html_e( 'Needs Action', 'woo-ai-review-manager' ); ?></div>
					<div>
						<div class="kpi-value"><?php echo absint( $needs_action_count ); ?></div>
						<div class="wairm-kpi-pills">
							<?php if ( $negative_needing_response > 0 ) : ?>
								<span class="wairm-kpi-pill pill-negative">
									<?php
									printf(
										/* translators: %d: number of negative reviews */
										esc_html__( '%d negative', 'woo-ai-review-manager' ),
										$negative_needing_response
									);
									?>
								</span>
							<?php endif; ?>
							<?php if ( $pending_count > 0 ) : ?>
								<span class="wairm-kpi-pill pill-pending">
									<?php
									printf(
										/* translators: %d: number of pending reviews */
										esc_html__( '%d pending', 'woo-ai-review-manager' ),
										$pending_count
									);
									?>
								</span>
							<?php endif; ?>
							<?php if ( 0 === $needs_action_count ) : ?>
								<span class="wairm-kpi-pill pill-clear"><?php esc_html_e( 'All clear', 'woo-ai-review-manager' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
```

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-dashboard-page.php
git commit -m "feat(dashboard): replace header, toolbar, and KPI cards with sparklines"
```

---

### Task 4: Add Sentiment Breakdown and Email Funnel Widgets

**Files:**
- Modify: `includes/admin/class-dashboard-page.php` (continue the `render_page()` HTML output, after the KPI cards grid)

- [ ] **Step 1: Add sentiment breakdown and email funnel HTML**

Insert immediately after the closing `</div>` of `.wairm-stats-grid` (from Task 3):

```php
			<!-- Mid Row: Sentiment Breakdown + Email Funnel -->
			<div class="wairm-mid-row">
				<div class="wairm-widget-card" role="region" aria-label="<?php esc_attr_e( 'Sentiment Breakdown', 'woo-ai-review-manager' ); ?>">
					<h2 class="widget-title"><?php esc_html_e( 'Sentiment Breakdown', 'woo-ai-review-manager' ); ?></h2>
					<?php if ( (int) ( $stats->total_reviews ?? 0 ) > 0 ) : ?>
						<?php
						$total       = (int) $stats->total_reviews;
						$sentiments  = [
							'positive' => [ 'count' => (int) $stats->positive, 'label' => __( 'Positive', 'woo-ai-review-manager' ) ],
							'neutral'  => [ 'count' => (int) $stats->neutral,  'label' => __( 'Neutral', 'woo-ai-review-manager' ) ],
							'negative' => [ 'count' => (int) $stats->negative, 'label' => __( 'Negative', 'woo-ai-review-manager' ) ],
						];
						foreach ( $sentiments as $key => $s ) :
							$pct = round( ( $s['count'] / $total ) * 100, 1 );
						?>
						<div class="wairm-sentiment-row">
							<div class="bar-label">
								<span class="bar-label-left">
									<span class="wairm-sentiment-dot dot-<?php echo esc_attr( $key ); ?>"></span>
									<span><?php echo esc_html( $s['label'] ); ?></span>
								</span>
								<span class="bar-label-right"
								      role="img"
								      aria-label="<?php printf( esc_attr__( '%1$s: %2$d reviews, %3$s percent', 'woo-ai-review-manager' ), esc_attr( $s['label'] ), $s['count'], $pct ); ?>">
									<?php echo absint( $s['count'] ); ?>
									<span class="bar-pct">(<?php echo esc_html( $pct ); ?>%)</span>
								</span>
							</div>
							<div class="wairm-bar-track">
								<div class="wairm-bar-fill fill-<?php echo esc_attr( $key === 'neutral' ? 'neutral' : $key ); ?>"
								     data-width="<?php echo esc_attr( (string) $pct ); ?>"
								     style="width: 0;"></div>
							</div>
						</div>
						<?php endforeach; ?>
					<?php else : ?>
						<div class="wairm-widget-empty">
							<p><?php esc_html_e( 'No reviews analyzed in this period.', 'woo-ai-review-manager' ); ?></p>
						</div>
					<?php endif; ?>
				</div>

				<div class="wairm-widget-card" role="region" aria-label="<?php esc_attr_e( 'Email Funnel', 'woo-ai-review-manager' ); ?>">
					<h2 class="widget-title"><?php esc_html_e( 'Email Funnel', 'woo-ai-review-manager' ); ?></h2>
					<?php if ( $email_funnel->sent > 0 ) : ?>
						<?php
						$funnel_steps = [
							[
								'label' => __( 'Sent', 'woo-ai-review-manager' ),
								'count' => $email_funnel->sent,
								'pct'   => 100,
								'width' => 100,
							],
							[
								'label' => __( 'Clicked', 'woo-ai-review-manager' ),
								'count' => $email_funnel->clicked,
								'pct'   => round( ( $email_funnel->clicked / $email_funnel->sent ) * 100, 1 ),
								'width' => round( ( $email_funnel->clicked / $email_funnel->sent ) * 100, 1 ),
							],
							[
								'label' => __( 'Reviewed', 'woo-ai-review-manager' ),
								'count' => $email_funnel->reviewed,
								'pct'   => round( ( $email_funnel->reviewed / $email_funnel->sent ) * 100, 1 ),
								'width' => round( ( $email_funnel->reviewed / $email_funnel->sent ) * 100, 1 ),
							],
						];

						// Find biggest drop-off step.
						$dropoffs = [
							$email_funnel->sent - $email_funnel->clicked,
							$email_funnel->clicked - $email_funnel->reviewed,
						];
						$biggest_dropoff_idx = $dropoffs[0] >= $dropoffs[1] ? 0 : 1;

						foreach ( $funnel_steps as $idx => $step ) :
						?>
						<div class="wairm-funnel-step">
							<div class="funnel-label">
								<span class="funnel-label-name"><?php echo esc_html( $step['label'] ); ?></span>
								<span class="funnel-label-value"
								      role="img"
								      aria-label="<?php printf( esc_attr__( '%1$s: %2$d, %3$s percent of sent', 'woo-ai-review-manager' ), esc_attr( $step['label'] ), $step['count'], $step['pct'] ); ?>">
									<?php echo absint( $step['count'] ); ?>
									<?php if ( $idx > 0 ) : ?>
										<span class="funnel-pct">(<?php echo esc_html( $step['pct'] ); ?>%)</span>
									<?php endif; ?>
								</span>
							</div>
							<div class="wairm-bar-track">
								<div class="wairm-bar-fill fill-accent"
								     data-width="<?php echo esc_attr( (string) $step['width'] ); ?>"
								     style="width: 0;"></div>
							</div>
						</div>
						<?php
						// Show drop-off annotation after the biggest drop step.
						if ( $idx < 2 && $idx === $biggest_dropoff_idx && $dropoffs[ $idx ] > 0 ) :
							$dropoff_pct = round( ( $dropoffs[ $idx ] / $email_funnel->sent ) * 100, 1 );
						?>
						<div class="wairm-funnel-dropoff">
							<?php
							printf(
								/* translators: %s: drop-off percentage */
								esc_html__( '%s%% drop-off', 'woo-ai-review-manager' ),
								$dropoff_pct
							);
							?>
						</div>
						<?php endif; ?>
						<?php endforeach; ?>
					<?php else : ?>
						<div class="wairm-widget-empty">
							<p><?php esc_html_e( 'No invitations sent in this period.', 'woo-ai-review-manager' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-invitations' ) ); ?>" class="empty-subtext">
								<?php esc_html_e( 'Go to Invitations', 'woo-ai-review-manager' ); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>
			</div>
```

- [ ] **Step 2: Commit**

```bash
git add includes/admin/class-dashboard-page.php
git commit -m "feat(dashboard): add sentiment breakdown bars and email funnel widgets"
```

---

### Task 5: Replace Bottom Row — Recent Reviews and Top Products

**Files:**
- Modify: `includes/admin/class-dashboard-page.php` (replace the existing two-column section, lines 374–496)

- [ ] **Step 1: Replace the two-column section and remove AI status box**

Replace everything from `<div class="wairm-two-column">` through the final closing `</div>` tags (lines 374–497) with:

```php
			<!-- Bottom Row: Recent Reviews + Top Products -->
			<div class="wairm-two-column">
				<div class="wairm-column">
					<div class="wairm-widget-card" role="region" aria-label="<?php esc_attr_e( 'Recent Reviews', 'woo-ai-review-manager' ); ?>">
						<h2 class="widget-title"><?php esc_html_e( 'Recent Reviews', 'woo-ai-review-manager' ); ?></h2>
						<?php if ( $recent ) : ?>
							<div class="wairm-recent-reviews">
								<?php foreach ( $recent as $review ) : ?>
								<div class="wairm-review-card">
									<div class="review-header">
										<span class="product-name"><?php echo esc_html( $review->product_name ); ?></span>
										<span class="sentiment-badge sentiment-<?php echo esc_attr( $review->sentiment ); ?>"><?php echo esc_html( ucfirst( $review->sentiment ) ); ?></span>
										<span class="review-date"><?php echo esc_html( human_time_diff( strtotime( $review->comment_date ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'woo-ai-review-manager' ) ); ?></span>
									</div>
									<div class="review-excerpt"><?php echo esc_html( wp_trim_words( $review->comment_content, 30 ) ); ?></div>
									<div class="review-meta">
										<?php if ( $review->ai_response_suggestion && 'sent' !== $review->ai_response_status ) : ?>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-responses&status=actionable' ) ); ?>" class="wairm-respond-link">
												<?php esc_html_e( 'Respond', 'woo-ai-review-manager' ); ?> &rarr;
											</a>
										<?php elseif ( 'sent' === $review->ai_response_status ) : ?>
											<span class="dashicons dashicons-yes-alt" title="<?php esc_attr_e( 'Reply posted', 'woo-ai-review-manager' ); ?>"></span>
										<?php endif; ?>
									</div>
								</div>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<div class="wairm-widget-empty">
								<p><?php esc_html_e( 'No reviews yet.', 'woo-ai-review-manager' ); ?></p>
								<p class="empty-subtext"><?php esc_html_e( 'Reviews will appear here once customers leave feedback and sentiment analysis runs.', 'woo-ai-review-manager' ); ?></p>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="wairm-column">
					<div class="wairm-widget-card" role="region" aria-label="<?php esc_attr_e( 'Top Products', 'woo-ai-review-manager' ); ?>">
						<h2 class="widget-title"><?php esc_html_e( 'Top Products', 'woo-ai-review-manager' ); ?></h2>
						<?php if ( $top_products ) : ?>
							<table class="wp-list-table widefat fixed striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Product', 'woo-ai-review-manager' ); ?></th>
										<th><?php esc_html_e( 'Reviews', 'woo-ai-review-manager' ); ?></th>
										<th><?php esc_html_e( 'Avg. Score', 'woo-ai-review-manager' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $top_products as $product ) : ?>
									<tr>
										<td><?php echo esc_html( $product->product_name ); ?></td>
										<td><?php echo absint( $product->review_count ); ?></td>
										<td>
											<?php echo esc_html( number_format( (float) $product->avg_score, 2 ) ); ?>
											<?php
											$score_class = $product->avg_score > 0.65 ? 'score-positive' : ( $product->avg_score > 0.35 ? 'score-mixed' : 'score-negative' );
											?>
											<span class="wairm-score-bar-track">
												<span class="wairm-score-bar-fill <?php echo esc_attr( $score_class ); ?>" style="width: <?php echo esc_attr( (string) round( (float) $product->avg_score * 100 ) ); ?>%;"></span>
											</span>
										</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							<div class="wairm-widget-empty">
								<p><?php esc_html_e( 'No product data yet.', 'woo-ai-review-manager' ); ?></p>
								<p class="empty-subtext"><?php esc_html_e( 'Product scores will appear after reviews are analyzed.', 'woo-ai-review-manager' ); ?></p>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
```

- [ ] **Step 2: Commit**

```bash
git add includes/admin/class-dashboard-page.php
git commit -m "feat(dashboard): replace bottom row, remove AI status box, add empty states"
```

---

### Task 6: Add Bar Fill Animation and Sparkline Tooltips in JavaScript

**Files:**
- Modify: `assets/js/dashboard.js`

- [ ] **Step 1: Add bar fill animation on DOMContentLoaded**

At the top of the `DOMContentLoaded` callback (after `'use strict';` on line 3), add:

```javascript
	// Animate bar fills on load.
	var bars = document.querySelectorAll( '.wairm-bar-fill[data-width]' );
	requestAnimationFrame( function () {
		bars.forEach( function ( bar ) {
			bar.style.width = bar.getAttribute( 'data-width' ) + '%';
		} );
	} );
```

- [ ] **Step 2: Add sparkline tooltip interactivity**

Directly after the bar fill animation code, add:

```javascript
	// Sparkline tooltips.
	var sparklines = document.querySelectorAll( '.wairm-kpi-sparkline' );
	sparklines.forEach( function ( container ) {
		var svg = container.querySelector( 'svg' );
		if ( ! svg ) return;

		var tooltip = document.createElement( 'div' );
		tooltip.className = 'wairm-sparkline-tooltip';
		container.appendChild( tooltip );

		svg.addEventListener( 'mouseenter', function () {
			tooltip.classList.add( 'is-visible' );
		} );

		svg.addEventListener( 'mouseleave', function () {
			tooltip.classList.remove( 'is-visible' );
		} );

		svg.addEventListener( 'mousemove', function ( e ) {
			var rect = svg.getBoundingClientRect();
			var x = e.clientX - rect.left;
			var pct = x / rect.width;

			// Determine which data series to show based on parent card.
			var card = container.closest( '.wairm-kpi-card' );
			var cardIndex = card ? Array.from( card.parentNode.children ).indexOf( card ) : 0;

			var series;
			if ( cardIndex === 0 ) series = wairm.sparkline_data.reviews;
			else if ( cardIndex === 1 ) series = wairm.sparkline_data.scores;
			else if ( cardIndex === 2 ) series = wairm.sparkline_data.conversions;

			if ( ! series || series.length === 0 ) return;

			var idx = Math.min( Math.round( pct * ( series.length - 1 ) ), series.length - 1 );
			var val = series[ idx ];

			// Format value based on card type.
			var display;
			if ( cardIndex === 1 ) display = val.toFixed( 2 );
			else if ( cardIndex === 2 ) display = val.toFixed( 1 ) + '%';
			else display = String( val );

			tooltip.textContent = display;
			tooltip.style.left = ( pct * 100 ) + '%';
			tooltip.style.bottom = '100%';
		} );
	} );
```

- [ ] **Step 3: Commit**

```bash
git add assets/js/dashboard.js
git commit -m "feat(dashboard): add bar fill animations and sparkline tooltip interactivity"
```

---

### Task 7: Add Respond Link CSS and Clean Up Old Stat Card Styles

**Files:**
- Modify: `assets/css/admin.css`

- [ ] **Step 1: Add respond link style**

Add after the review card styles (after the `.wairm-review-card .review-meta .dashicons-yes-alt` rule, around line 206):

```css
.wairm-respond-link {
	font-family: var(--font-body);
	font-size: 11px;
	font-weight: 600;
	color: var(--accent);
	text-decoration: none;
	cursor: pointer;
	transition: text-decoration 0.15s ease;
}

.wairm-respond-link:hover {
	text-decoration: underline;
}
```

- [ ] **Step 2: Update the old stat card border styles**

The old `.wairm-stat-card.positive`, `.wairm-stat-card.neutral`, `.wairm-stat-card.negative` color rules (lines 95–101) and the `border-top: 3px` rules are no longer used. Replace them with:

```css
/* Legacy stat-card color borders removed — KPI cards use .wairm-kpi-card instead. */
```

- [ ] **Step 3: Update the `.wairm-stat-card` border to 1.5px**

Change the `.wairm-stat-card` rule at line 50 from:
```css
	border: 1px solid var(--border);
```
to:
```css
	border: 1.5px solid var(--border);
```

- [ ] **Step 4: Commit**

```bash
git add assets/css/admin.css
git commit -m "refactor(dashboard): add respond link style, clean up old stat card borders"
```

---

### Task 8: Verify and Test

**Files:**
- All modified files

- [ ] **Step 1: Verify PHP syntax**

```bash
php -l includes/admin/class-dashboard-page.php
```

Expected: `No syntax errors detected`

- [ ] **Step 2: Check for CSS syntax issues**

```bash
grep -c '{' assets/css/admin.css && grep -c '}' assets/css/admin.css
```

Expected: Both counts should be equal (balanced braces).

- [ ] **Step 3: Verify all data-width attributes are set for bar fills**

```bash
grep -n 'data-width' includes/admin/class-dashboard-page.php
```

Expected: Matches in sentiment bars and email funnel bars.

- [ ] **Step 4: Verify ARIA attributes are present**

```bash
grep -n 'aria-label\|aria-pressed\|role=' includes/admin/class-dashboard-page.php | head -20
```

Expected: Multiple matches for KPI cards, widgets, period filter, and bars.

- [ ] **Step 5: Verify reduced-motion media query exists**

```bash
grep -n 'prefers-reduced-motion' assets/css/admin.css
```

Expected: At least one match inside the `@media` block.

- [ ] **Step 6: Manual test in browser**

Open the WordPress admin and navigate to AI Reviews > Dashboard. Verify:
1. Four KPI cards with sparklines and deltas display correctly
2. Sentiment Breakdown shows three individual bars
3. Email Funnel shows three progressive bars with drop-off annotation
4. Period filter changes data in all widgets
5. Toolbar buttons link to correct pages
6. Empty states show when no data exists (test with "All time" on fresh install)
7. Bar fill animations play on page load
8. Sparkline tooltips appear on hover
9. Tab key navigates through interactive elements with visible focus rings
10. `prefers-reduced-motion` disables animations (test in browser dev tools)

- [ ] **Step 7: Final commit**

```bash
git add -A
git commit -m "chore(dashboard): verify implementation matches spec"
```
