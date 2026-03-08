<?php
/**
 * Admin settings page.
 *
 * @package WooAIReviewManager
 */

declare(strict_types=1);

namespace WooAIReviewManager\Admin;

defined( 'ABSPATH' ) || exit;

final class Settings_Page {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_submenu_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function add_submenu_page(): void {
		add_submenu_page(
			'wairm-dashboard',
			__( 'Settings', 'woo-ai-review-manager' ),
			__( 'Settings', 'woo-ai-review-manager' ),
			'manage_woocommerce',
			'wairm-settings',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'wairm_settings', 'wairm_gemini_api_key' );
		register_setting( 'wairm_settings', 'wairm_invitation_delay_days' );
		register_setting( 'wairm_settings', 'wairm_reminder_enabled' );
		register_setting( 'wairm_settings', 'wairm_reminder_delay_days' );
		register_setting( 'wairm_settings', 'wairm_invitation_expiry_days' );
		register_setting( 'wairm_settings', 'wairm_email_from_name' );
		register_setting( 'wairm_settings', 'wairm_email_subject' );
		register_setting( 'wairm_settings', 'wairm_auto_analyze' );
		register_setting( 'wairm_settings', 'wairm_auto_respond_positive' );
		register_setting( 'wairm_settings', 'wairm_negative_threshold' );
	}

	public function render_page(): void {
		$active_tab = sanitize_key( $_GET['tab'] ?? 'api' );
		?>
		<div class="wrap wairm-settings">
			<h1><?php esc_html_e( 'AI Review Manager Settings', 'woo-ai-review-manager' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-settings&tab=api' ) ); ?>" class="nav-tab <?php echo 'api' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'API Settings', 'woo-ai-review-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-settings&tab=email' ) ); ?>" class="nav-tab <?php echo 'email' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Email Settings', 'woo-ai-review-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wairm-settings&tab=general' ) ); ?>" class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'General', 'woo-ai-review-manager' ); ?>
				</a>
			</nav>

			<form method="post" action="options.php" class="wairm-settings-form">
				<?php settings_fields( 'wairm_settings' ); ?>

				<?php if ( 'api' === $active_tab ) : ?>
					<h2><?php esc_html_e( 'Gemini API Configuration', 'woo-ai-review-manager' ); ?></h2>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wairm_gemini_api_key"><?php esc_html_e( 'Gemini API Key', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="password" id="wairm_gemini_api_key" name="wairm_gemini_api_key" value="<?php echo esc_attr( get_option( 'wairm_gemini_api_key', '' ) ); ?>" class="regular-text" />
								<p class="description">
									<?php printf(
										/* translators: %s: Google AI Studio URL */
										esc_html__( 'Get your API key from %s', 'woo-ai-review-manager' ),
										'<a href="https://makersuite.google.com/app/apikey" target="_blank">Google AI Studio</a>'
									); ?>
								</p>
								<p class="description">
									<strong><?php esc_html_e( 'Required for sentiment analysis and AI response generation.', 'woo-ai-review-manager' ); ?></strong>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_negative_threshold"><?php esc_html_e( 'Negative Sentiment Threshold', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="number" id="wairm_negative_threshold" name="wairm_negative_threshold" value="<?php echo esc_attr( get_option( 'wairm_negative_threshold', '0.30' ) ); ?>" min="0" max="1" step="0.05" />
								<p class="description">
									<?php esc_html_e( 'Reviews with sentiment score below this threshold will be flagged as "negative" and receive AI response suggestions.', 'woo-ai-review-manager' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'Range: 0.0 (most negative) to 1.0 (most positive). Default: 0.30.', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
					</table>

				<?php elseif ( 'email' === $active_tab ) : ?>
					<h2><?php esc_html_e( 'Review Invitation Email Settings', 'woo-ai-review-manager' ); ?></h2>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wairm_invitation_delay_days"><?php esc_html_e( 'Initial Invitation Delay', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="number" id="wairm_invitation_delay_days" name="wairm_invitation_delay_days" value="<?php echo esc_attr( get_option( 'wairm_invitation_delay_days', '7' ) ); ?>" min="1" max="30" />
								<span><?php esc_html_e( 'days after order completion', 'woo-ai-review-manager' ); ?></span>
								<p class="description">
									<?php esc_html_e( 'How many days to wait before sending the first review invitation.', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_reminder_enabled"><?php esc_html_e( 'Send Reminder', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="wairm_reminder_enabled" name="wairm_reminder_enabled" value="yes" <?php checked( get_option( 'wairm_reminder_enabled', 'yes' ), 'yes' ); ?> />
									<?php esc_html_e( 'Send a reminder email if the customer doesn\'t review', 'woo-ai-review-manager' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_reminder_delay_days"><?php esc_html_e( 'Reminder Delay', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="number" id="wairm_reminder_delay_days" name="wairm_reminder_delay_days" value="<?php echo esc_attr( get_option( 'wairm_reminder_delay_days', '14' ) ); ?>" min="1" max="60" />
								<span><?php esc_html_e( 'days after initial invitation', 'woo-ai-review-manager' ); ?></span>
								<p class="description">
									<?php esc_html_e( 'Only applies if reminder is enabled.', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_invitation_expiry_days"><?php esc_html_e( 'Invitation Expiry', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="number" id="wairm_invitation_expiry_days" name="wairm_invitation_expiry_days" value="<?php echo esc_attr( get_option( 'wairm_invitation_expiry_days', '30' ) ); ?>" min="7" max="90" />
								<span><?php esc_html_e( 'days', 'woo-ai-review-manager' ); ?></span>
								<p class="description">
									<?php esc_html_e( 'Review links will expire after this many days.', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_email_from_name"><?php esc_html_e( 'Email From Name', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="text" id="wairm_email_from_name" name="wairm_email_from_name" value="<?php echo esc_attr( get_option( 'wairm_email_from_name', get_bloginfo( 'name' ) ) ); ?>" class="regular-text" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_email_subject"><?php esc_html_e( 'Email Subject Line', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="text" id="wairm_email_subject" name="wairm_email_subject" value="<?php echo esc_attr( get_option( 'wairm_email_subject', __( 'How was your recent purchase?', 'woo-ai-review-manager' ) ) ); ?>" class="regular-text" />
								<p class="description">
									<?php esc_html_e( 'Available placeholders: {customer_name}, {store_name}', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
					</table>

				<?php elseif ( 'general' === $active_tab ) : ?>
					<h2><?php esc_html_e( 'General Settings', 'woo-ai-review-manager' ); ?></h2>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wairm_auto_analyze"><?php esc_html_e( 'Auto‑Analyze New Reviews', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="wairm_auto_analyze" name="wairm_auto_analyze" value="yes" <?php checked( get_option( 'wairm_auto_analyze', 'yes' ), 'yes' ); ?> />
									<?php esc_html_e( 'Automatically analyze new reviews for sentiment', 'woo-ai-review-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When enabled, all new WooCommerce reviews will be sent to Gemini for sentiment analysis.', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_auto_respond_positive"><?php esc_html_e( 'Auto-respond to Positive Reviews', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="wairm_auto_respond_positive" name="wairm_auto_respond_positive" value="yes" <?php checked( get_option( 'wairm_auto_respond_positive', 'no' ), 'yes' ); ?> />
									<?php esc_html_e( 'Automatically generate response suggestions for positive reviews', 'woo-ai-review-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'By default, only negative reviews get AI response suggestions. Enable this to get suggestions for positive reviews too.', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Data & Privacy', 'woo-ai-review-manager' ); ?>
							</th>
							<td>
								<p>
									<?php esc_html_e( 'This plugin sends review text to Google\'s Gemini API for sentiment analysis and response generation.', 'woo-ai-review-manager' ); ?>
								</p>
								<p>
									<?php printf(
										/* translators: %s: Google privacy policy URL */
										esc_html__( 'Review Google\'s %s for details on how they handle your data.', 'woo-ai-review-manager' ),
										'<a href="https://policies.google.com/privacy" target="_blank">' . esc_html__( 'Privacy Policy', 'woo-ai-review-manager' ) . '</a>'
									); ?>
								</p>
								<p>
									<?php esc_html_e( 'No customer personally identifiable information (PII) is sent to Gemini—only the review text and product name.', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
					</table>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}