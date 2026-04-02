# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WooCommerce AI Review Manager — a WordPress plugin that collects customer reviews, runs AI sentiment analysis, generates response suggestions, and sends post-purchase invitation emails. It targets **WordPress 7.0+** with the new AI Client API (`wp_ai_client_prompt`), **WooCommerce 8.0+**, and **PHP 8.0+**.

## Development Environment

This plugin runs inside a WordPress installation. There is no build step, no bundler, and no test framework configured. CSS and JS are plain files in `assets/` — no compilation needed.

To test locally, activate the plugin in a WordPress site with WooCommerce installed. An AI provider must be configured in **Settings > Connectors** for sentiment analysis and response generation to work.

## Architecture

### Bootstrap Flow

`woo-ai-review-manager.php` defines constants (`WAIRM_*`), checks dependencies (WooCommerce + `wp_ai_client_prompt`), registers the autoloader, then calls `Plugin::instance()` on `plugins_loaded` priority 20.

### Autoloader

`Autoloader` maps the `WooAIReviewManager\` namespace to `includes/` using a WordPress-style naming convention: `Some_Class` resolves to `includes/class-some-class.php`, and sub-namespaces map to subdirectories (e.g., `Admin\Dashboard_Page` -> `includes/admin/class-dashboard-page.php`).

### Core Data Flow

1. **Review Collection** (`Review_Collector`): Hooks `woocommerce_order_status_completed` to create invitation records and queue emails. Hooks `comment_post` and `wp_insert_comment` to detect new reviews and queue them for analysis via WooCommerce Action Scheduler (`as_enqueue_async_action`), falling back to synchronous if Action Scheduler is unavailable.

2. **Sentiment Analysis** (`Sentiment_Analyzer`): Listens for `wairm_analyze_single_review` action. Calls `AI_Client::analyze_sentiment()` which returns `{sentiment, score, key_phrases}` as structured JSON. Results stored in `wp_wairm_review_sentiment`. Automatically generates response suggestions for negative reviews (score < threshold) and optionally for positive reviews.

3. **Response Generation** (`Response_Generator`): Handles AJAX actions for regenerating, approving, posting, and dismissing AI response suggestions. Posts approved responses as WooCommerce comment replies.

4. **Email System** (`Email_Sender`): Processes a queue table (`wp_wairm_email_queue`) every 5 minutes via WP-Cron. Handles initial invitations and reminders. Serves a token-based review form at `?wairm_review_form={token}`.

5. **AI Client** (`AI_Client`): Wraps the WordPress 7.0 AI Client API. All AI calls go through `wp_ai_client_prompt()` with structured JSON schemas for sentiment analysis and insights. Supports model preference via `wairm_model_preference` option. Provider credentials are managed externally in WordPress Connectors.

6. **Insights** (`Admin\Insights_Page` + `AI_Client::generate_insights()`): Four insight categories — product, trends, operational, strategic — each with its own JSON schema. Results cached in `wp_wairm_insights` table.

### Custom Database Tables

- `wp_wairm_review_invitations` — invitation tokens, statuses, customer info
- `wp_wairm_review_sentiment` — sentiment scores, key phrases, AI response suggestions
- `wp_wairm_email_queue` — scheduled emails (initial + reminder)
- `wp_wairm_insights` — cached AI insight results

Tables are created/updated via `dbDelta()` in `Installer::create_tables()`. The `Plugin::maybe_upgrade()` method re-runs the installer when the stored version is outdated.

### Admin Pages

All registered under the "AI Reviews" menu (`wairm-dashboard` slug). Five pages: Dashboard, Responses, Invitations, Insights, Settings. Each page class handles its own asset enqueuing and AJAX endpoints.

### REST API

Two controllers under `wairm/v1`:
- `GET /reviews` — list reviews with sentiment data
- `GET /sentiment/product/{id}` — per-product sentiment aggregation

### WP-Cron Events

- `wairm_send_review_invitations` — every 5 minutes, processes email queue
- `wairm_expire_invitations` — daily, expires stale invitations and cancels their queued emails

## Coding Conventions

- All PHP files use `declare(strict_types=1)` and the `WooAIReviewManager` namespace
- Options are prefixed `wairm_`
- Database tables are prefixed `wairm_`
- AJAX actions are prefixed `wairm_`
- Text domain: `woo-ai-review-manager`
- Translations exist for `de_DE` and `es_ES` in `languages/`
- AI prompts instruct the model to respond in the site's locale language via `get_locale()`

## Key Options

| Option | Default | Purpose |
|---|---|---|
| `wairm_negative_threshold` | `0.30` | Score below which reviews are flagged negative |
| `wairm_auto_analyze` | `yes` | Auto-analyze new reviews |
| `wairm_auto_respond_positive` | `no` | Generate AI responses for positive reviews too |
| `wairm_model_preference` | `''` | Preferred AI model ID passed to WP AI Client |
| `wairm_support_email` | admin email | Email shown in AI-generated responses for support |
