# WooCommerce AI Review Manager

Automated review collection, sentiment analysis, and AI-powered response suggestions for WooCommerce stores.

## Features

- **Automated Review Invitations**: Automatically sends email invitations to customers after order completion.
- **Sentiment Analysis**: Uses Google's Gemini API to analyze review sentiment (positive/neutral/negative).
- **AI Response Suggestions**: Generates store‑owner response suggestions for negative reviews.
- **Sentiment Dashboard**: WordPress admin dashboard showing sentiment breakdown by product.
- **Reminder Emails**: Optional reminder emails for customers who don't review.
- **Token‑Based Review Links**: Secure, expiring review links in customer emails.
- **HPOS Compatible**: Fully compatible with WooCommerce's High‑Performance Order Storage.
- **Privacy‑Focused**: Only review text and product name are sent to Gemini API—no PII.

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.0+
- Gemini API key (free tier available)

## Installation

1. Download the plugin ZIP or clone this repository.
2. Upload the `woo-ai-review-manager` folder to `/wp-content/plugins/`.
3. Activate the plugin through the WordPress admin.
4. Go to **AI Reviews → Settings** to configure your Gemini API key.

## Configuration

### 1. Get a Gemini API Key
1. Visit [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Create a new API key (free tier available)
3. Copy the key

### 2. Plugin Settings
Navigate to **AI Reviews → Settings**:

#### API Settings
- **Gemini API Key**: Paste your API key here
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
1. New reviews are automatically sent to Gemini API for sentiment analysis (if enabled).
2. Each review receives a sentiment label (positive/neutral/negative) and a score (0.0–1.0).
3. Key phrases are extracted from the review text.
4. For negative reviews (score < threshold), AI response suggestions are generated.

### Dashboard
1. Go to **AI Reviews** in the WordPress admin.
2. View overall sentiment statistics.
3. See recent reviews with sentiment labels.
4. View top products by review count.
5. Read AI response suggestions for negative reviews.

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
- `WooAIReviewManager\Sentiment_Analyzer` – Processes reviews through Gemini
- `WooAIReviewManager\Gemini_Client` – Gemini API wrapper
- `WooAIReviewManager\Email_Sender` – Handles email sending and review forms
- `WooAIReviewManager\Admin\Dashboard_Page` – Admin dashboard
- `WooAIReviewManager\Admin\Settings_Page` – Settings UI

### Cron Events
- `wairm_process_pending_reviews` – Hourly sentiment analysis of unanalyzed reviews
- `wairm_send_review_invitations` – Every 5 minutes sends queued emails

### REST API
- `GET /wp-json/wairm/v1/reviews` – List reviews with sentiment
- `GET /wp-json/wairm/v1/sentiment/product/{id}` – Sentiment stats for a product

## Privacy & Security

- **No PII to Gemini**: Only review text and product name are sent. No customer name, email, or order details.
- **API Key Storage**: Encrypted via WordPress options API.
- **Token‑Based Links**: Review links expire and cannot be guessed.
- **Email Templates**: Styled HTML templates with unsubscribe hint.

## FAQ

### Is the Gemini API free?
Yes, Gemini has a generous free tier. Check [Google's pricing](https://ai.google.dev/pricing) for details.

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
- [ ] Multiple language support
- [ ] Review moderation workflow
- [ ] Export sentiment reports (CSV)
- [ ] Webhook notifications for negative reviews
- [ ] A/B testing of email subject lines
- [ ] WooCommerce email template integration
- [ ] Review rating prediction

## Support
Create an issue on [GitHub](https://github.com/valhalla-open-org/woo-ai-review-manager).

## License
GPL‑2.0‑or‑later