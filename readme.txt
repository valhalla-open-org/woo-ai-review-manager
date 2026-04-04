=== WooCommerce AI Review Manager ===
Contributors: valhallaopen
Tags: woocommerce, reviews, ai, sentiment analysis, review management
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated review collection, sentiment analysis, and AI-powered response suggestions for WooCommerce stores.

== Description ==

WooCommerce AI Review Manager helps store owners collect, analyze, and respond to customer reviews using AI.

**Key Features:**

* **Post-Purchase Review Invitations** - Automatically send email invitations after order completion with customizable templates and reminders.
* **AI Sentiment Analysis** - Analyze review sentiment, score, and key phrases using the WordPress AI Client API.
* **Smart Response Suggestions** - AI-generated response suggestions for negative reviews, with optional positive review responses.
* **Analytics Dashboard** - Visual dashboard with sentiment trends, KPI cards, and email funnel metrics.
* **AI-Powered Insights** - Generate product, trend, operational, and strategic insights from your review data.
* **CSV Export** - Export review and sentiment data for external analysis.

**Requirements:**

* WordPress 7.0+ with AI Client API
* WooCommerce 8.0+
* PHP 8.0+
* An AI provider configured in Settings > Connectors

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure WooCommerce is installed and active.
4. Configure an AI provider in **Settings > Connectors**.
5. Visit **AI Reviews > Settings** to customize your preferences.

== Frequently Asked Questions ==

= What AI providers are supported? =

Any provider configured through the WordPress 7.0 Connectors system. The plugin uses the standard `wp_ai_client_prompt()` API.

= Does it work without an AI provider? =

The plugin will activate, but sentiment analysis and AI response generation require a configured AI provider.

== Changelog ==

= 2.1.0 =
* Added Freemius integration for licensing and updates.
* Improved admin notice placement on dashboard.

= 2.0.0 =
* Analytics dashboard with KPI cards, sentiment bars, sparklines, and email funnel.
* Previous period comparisons and delta indicators.

= 1.0.0 =
* Initial release.
