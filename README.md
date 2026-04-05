# AI Review Manager for WooCommerce

**Turn every customer review into a growth opportunity — automatically.**

AI Review Manager for WooCommerce uses the power of AI to analyze your store's reviews, generate thoughtful responses, and proactively collect new feedback — all from one intuitive dashboard.

---

## What It Does

### Sentiment Analysis on Autopilot
Every new review is automatically scored and classified as positive, neutral, or negative. See at a glance how customers feel about each product with sentiment scores, key phrase extraction, and trend visualizations — no spreadsheets required.

### AI-Crafted Response Suggestions
Stop staring at a blank reply box. The plugin generates tailored responses for every review — empathetic and solution-oriented for complaints, warm and genuine for praise. Edit them to add your personal touch, or post them as-is with a single click.

### One-Click Response Workflow
Review, approve, edit, and post AI suggestions as real WooCommerce replies — all without leaving your dashboard. A clear status pipeline (New → Approved → Posted) keeps your team aligned and nothing falls through the cracks.

### Proactive Review Collection
Automatically email customers after purchase inviting them to leave a review. Customize the timing, email subject, and message. Smart reminders follow up with customers who haven't responded yet, and expired invitations are cleaned up automatically.

### Real-Time Dashboard
Your command center for customer sentiment. At a glance you see:
- Total reviews analyzed with positive/neutral/negative breakdown
- Sentiment distribution chart
- Top products by review volume and score
- Pending items that need your attention with direct action links
- AI service health status

### Bulk Analysis
Existing store with hundreds of reviews? Hit one button and watch the progress bar as the plugin analyzes your entire review history in batches — no timeouts, no server strain.

---

## Built for WordPress 7.0

Powered by the new **WordPress AI Client API**, the plugin works with any AI provider you've configured in WordPress — Anthropic, OpenAI, Google, or any future provider. No separate API keys to manage inside the plugin. Just connect your preferred AI in **Settings → Connectors** and you're ready to go.

---

## Key Features

- **Provider-agnostic AI** — works with any WordPress AI Client provider
- **Automatic sentiment scoring** — every review analyzed as it arrives
- **Smart response generation** — tone-matched to each review's sentiment
- **Full response workflow** — approve, edit, post, dismiss, or regenerate
- **Email invitation system** — customizable post-purchase review requests with reminders
- **Batch analysis** — process your entire review history with a progress bar
- **REST API** — endpoints for reviews and per-product sentiment aggregation
- **WooCommerce HPOS compatible**
- **Clean uninstall** — removes all data and scheduled tasks when deactivated
- **Privacy-focused** — only review text and product name are sent to the AI provider, no PII

*Less time managing reviews. More time growing your store.*

---

## Requirements

- WordPress 7.0+
- WooCommerce 8.0+
- PHP 8.0+
- An AI provider configured via **Settings → Connectors** (Anthropic, Google, OpenAI, or any community connector)

## Installation

1. Download the plugin ZIP or clone this repository.
2. Upload the `ai-review-manager-for-woocommerce` folder to `/wp-content/plugins/`.
3. Activate the plugin through the WordPress admin.
4. Go to **Settings → Connectors** to configure your preferred AI provider.

## Configuration

### 1. Configure an AI Provider
1. Go to **Settings → Connectors** in WordPress admin.
2. Enter API credentials for your preferred provider (Anthropic, Google, OpenAI, or install a community connector plugin).
3. The plugin will automatically use the configured provider through the WordPress AI Client API.

### 2. Plugin Settings
Navigate to **AI Reviews → Settings**:

#### AI Settings
- **AI Provider**: Shows the status of the WordPress AI Client. Credentials are managed in **Settings → Connectors**.
- **Negative Sentiment Threshold**: Score below which reviews are flagged as "negative" (default 0.30)

#### Email Settings
- **Initial Invitation Delay**: Days after order completion before sending first review invitation
- **Send Reminder**: Enable/disable reminder emails
- **Reminder Delay**: Days after initial invitation
- **Invitation Expiry**: Days before review link expires
- **Email From Name**: Sender name for invitation emails
- **Email Subject**: Subject line (placeholders: `{customer_name}`, `{store_name}`)

#### General Settings
- **Auto‑Analyze New Reviews**: Automatically analyze sentiment of new reviews
- **Auto‑respond to Positive Reviews**: Generate AI response suggestions for positive reviews too

## Usage

### Review Collection
1. When a WooCommerce order is marked "completed," an invitation is scheduled.
2. After the configured delay, the customer receives a branded email with a secure review link.
3. Customers click the link and are taken to a review form for each product purchased.
4. Reviews are submitted as normal WooCommerce product reviews.

### Sentiment Analysis
1. New reviews are automatically sent to the configured AI provider for sentiment analysis (if enabled).
2. Each review receives a sentiment label (positive/neutral/negative) and a score (0.0–1.0).
3. Key phrases are extracted from the review text.
4. For negative reviews (score < threshold), AI response suggestions are generated.

### Dashboard
1. Go to **AI Reviews** in the WordPress admin.
2. View overall sentiment statistics and distribution chart.
3. See recent reviews with sentiment labels and quick-action links.
4. View top products by review count and average score.
5. Use "Analyze Unanalyzed Reviews" to batch-process existing reviews with a progress bar.
6. Click "Manage Responses" to review, approve, edit, and post AI suggestions.

### Response Management
1. Go to **AI Reviews → AI Responses**.
2. Filter by status: Needs Action, New, Approved, Posted, Dismissed, or All.
3. Edit the AI suggestion text to add your personal touch.
4. **Approve** to save for later, **Post Reply** to publish as a WooCommerce reply, or **Dismiss** to skip.
5. **Regenerate** to get a fresh AI suggestion for any review.

## Database Schema

The plugin creates three custom tables:

### `wp_wairm_review_invitations`
- Stores review invitation tokens, statuses, and customer info.

### `wp_wairm_review_sentiment`
- Stores sentiment analysis results for each review.

### `wp_wairm_email_queue`
- Queues emails for batch sending (initial invitations and reminders).

## Development

### Code Standards
- PSR‑4 autoloading with namespaces
- Strict typing (`declare(strict_types=1)`)
- WordPress coding standards
- WooCommerce best practices

### Architecture
- `WooAIReviewManager\Plugin` – Main orchestrator
- `WooAIReviewManager\Review_Collector` – Hooks into WooCommerce orders
- `WooAIReviewManager\Sentiment_Analyzer` – Processes reviews through the WordPress AI Client
- `WooAIReviewManager\AI_Client` – WordPress AI Client wrapper
- `WooAIReviewManager\Response_Generator` – On-demand AI response generation
- `WooAIReviewManager\Email_Sender` – Handles email sending and review forms
- `WooAIReviewManager\Admin\Dashboard_Page` – Admin dashboard with analytics
- `WooAIReviewManager\Admin\Responses_Page` – Response management workflow
- `WooAIReviewManager\Admin\Settings_Page` – Settings UI
- `WooAIReviewManager\API\Reviews_Controller` – REST API for reviews
- `WooAIReviewManager\API\Sentiment_Controller` – REST API for sentiment stats

### Cron Events
- `wairm_process_pending_reviews` – Hourly sentiment analysis of unanalyzed reviews
- `wairm_send_review_invitations` – Every 5 minutes sends queued emails
- `wairm_expire_invitations` – Daily cleanup of expired review invitations

### REST API
- `GET /wp-json/wairm/v1/reviews` – List reviews with sentiment
- `GET /wp-json/wairm/v1/sentiment/product/{id}` – Sentiment stats for a product

## Privacy & Security

- **No PII to AI Provider**: Only review text and product name are sent. No customer name, email, or order details.
- **Credential Management**: API keys are managed through WordPress's built‑in Connectors system.
- **Token‑Based Links**: Review links expire and cannot be guessed.
- **Email Templates**: Styled HTML templates with unsubscribe hint.

## FAQ

### Which AI providers are supported?
Any provider configured through the WordPress AI Client API. WordPress 7.0 ships with built‑in support for Anthropic, Google, and OpenAI. Community connector plugins can add additional providers.

### Can I customize email templates?
Currently, templates are hard‑coded in `class-email-sender.php`. Future versions may add template customisation.

### What happens if the API fails?
If sentiment analysis fails, the review will remain unanalyzed. No customer‑facing impact.

### Can I analyze existing reviews?
Yes, use the "Analyze Old Reviews" button on the dashboard.

### Can I disable sentiment analysis?
Yes, uncheck "Auto‑Analyze New Reviews" in settings.

## Roadmap

- [ ] Email template customisation
- [ ] Export sentiment reports (CSV)
- [ ] Webhook/Slack notifications for negative reviews
- [ ] A/B testing of email subject lines
- [ ] WooCommerce email template integration
- [ ] Review rating prediction
- [ ] Bulk response actions (approve/post/dismiss multiple at once)

## Support
Create an issue on [GitHub](https://github.com/valhalla-open-org/ai-review-manager-for-woocommerce).

## License
GPL‑2.0‑or‑later