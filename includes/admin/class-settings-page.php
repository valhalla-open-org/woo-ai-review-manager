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
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_wairm_send_test_email', [ $this, 'ajax_send_test_email' ] );
		add_action( 'wp_ajax_wairm_delete_all_data', [ $this, 'ajax_delete_all_data' ] );
		add_action( 'wp_ajax_wairm_preview_email', [ $this, 'ajax_preview_email' ] );
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'ai-reviews_page_wairm-settings' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wairm-admin',
			WAIRM_PLUGIN_URL . 'assets/css/admin.css',
			[],
			WAIRM_VERSION
		);

		wp_enqueue_script(
			'wairm-settings',
			WAIRM_PLUGIN_URL . 'assets/js/settings.js',
			[],
			WAIRM_VERSION,
			true
		);

		wp_localize_script(
			'wairm-settings',
			'wairmSettings',
			[
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'wairm_settings' ),
				'preview_url' => admin_url( 'admin-ajax.php?action=wairm_preview_email&nonce=' . wp_create_nonce( 'wairm_settings' ) ),
				'i18n'        => [
					'sending'        => __( 'Sending...', 'woo-ai-review-manager' ),
					'send_test'      => __( 'Send Test Email', 'woo-ai-review-manager' ),
					'sent'           => __( 'Test email sent!', 'woo-ai-review-manager' ),
					'error'          => __( 'Failed to send test email.', 'woo-ai-review-manager' ),
					'confirm_delete' => __( 'Are you sure you want to delete ALL plugin data? This will remove all sentiment records, invitations, email queue entries, insights, and plugin settings. This action cannot be undone.', 'woo-ai-review-manager' ),
					'deleting'       => __( 'Deleting...', 'woo-ai-review-manager' ),
					'deleted'        => __( 'All data deleted. Reloading...', 'woo-ai-review-manager' ),
					'delete_error'   => __( 'Failed to delete data.', 'woo-ai-review-manager' ),
				],
			]
		);
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
		// API tab settings.
		register_setting( 'wairm_settings_api', 'wairm_negative_threshold', [
			'type'              => 'number',
			'sanitize_callback' => [ $this, 'sanitize_threshold' ],
			'default'           => 0.30,
		] );
		register_setting( 'wairm_settings_api', 'wairm_model_preference', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );

		// Email tab settings.
		register_setting( 'wairm_settings_email', 'wairm_invitation_delay_days', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 7,
		] );
		register_setting( 'wairm_settings_email', 'wairm_reminder_enabled', [
			'type'              => 'string',
			'sanitize_callback' => fn( $v ) => $this->sanitize_pro_only( 'wairm_reminder_enabled', $v, [ $this, 'sanitize_yes_no' ] ),
			'default'           => 'yes',
		] );
		register_setting( 'wairm_settings_email', 'wairm_reminder_delay_days', [
			'type'              => 'integer',
			'sanitize_callback' => fn( $v ) => $this->sanitize_pro_only( 'wairm_reminder_delay_days', $v, 'absint' ),
			'default'           => 14,
		] );
		register_setting( 'wairm_settings_email', 'wairm_invitation_expiry_days', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 30,
		] );
		register_setting( 'wairm_settings_email', 'wairm_email_from_name', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );
		register_setting( 'wairm_settings_email', 'wairm_email_subject', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );
		register_setting( 'wairm_settings_email', 'wairm_email_greeting', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );
		register_setting( 'wairm_settings_email', 'wairm_email_body_text', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		] );
		register_setting( 'wairm_settings_email', 'wairm_email_button_text', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );

		// Reminder email content (pro only).
		register_setting( 'wairm_settings_email', 'wairm_reminder_subject', [
			'type'              => 'string',
			'sanitize_callback' => fn( $v ) => $this->sanitize_pro_only( 'wairm_reminder_subject', $v, 'sanitize_text_field' ),
			'default'           => '',
		] );
		register_setting( 'wairm_settings_email', 'wairm_reminder_greeting', [
			'type'              => 'string',
			'sanitize_callback' => fn( $v ) => $this->sanitize_pro_only( 'wairm_reminder_greeting', $v, 'sanitize_text_field' ),
			'default'           => '',
		] );
		register_setting( 'wairm_settings_email', 'wairm_reminder_body_text', [
			'type'              => 'string',
			'sanitize_callback' => fn( $v ) => $this->sanitize_pro_only( 'wairm_reminder_body_text', $v, 'sanitize_textarea_field' ),
			'default'           => '',
		] );
		register_setting( 'wairm_settings_email', 'wairm_reminder_button_text', [
			'type'              => 'string',
			'sanitize_callback' => fn( $v ) => $this->sanitize_pro_only( 'wairm_reminder_button_text', $v, 'sanitize_text_field' ),
			'default'           => '',
		] );

		// General tab settings.
		register_setting( 'wairm_settings_general', 'wairm_auto_analyze', [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_yes_no' ],
			'default'           => 'yes',
		] );
		register_setting( 'wairm_settings_general', 'wairm_auto_respond_positive', [
			'type'              => 'string',
			'sanitize_callback' => fn( $v ) => $this->sanitize_pro_only( 'wairm_auto_respond_positive', $v, [ $this, 'sanitize_yes_no' ] ),
			'default'           => 'no',
		] );
		register_setting( 'wairm_settings_general', 'wairm_reply_as', [
			'type'              => 'string',
			'sanitize_callback' => fn( $v ) => $this->sanitize_pro_only( 'wairm_reply_as', $v, 'sanitize_key' ),
			'default'           => 'store',
		] );
		register_setting( 'wairm_settings_general', 'wairm_support_email', [
			'type'              => 'string',
			'sanitize_callback' => fn( $v ) => $this->sanitize_pro_only( 'wairm_support_email', $v, 'sanitize_email' ),
			'default'           => '',
		] );
	}

	/**
	 * Sanitize a yes/no checkbox value.
	 */
	public function sanitize_yes_no( $value ): string {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * Return existing option value if user is not on a paid plan.
	 */
	private function sanitize_pro_only( string $option, $value, callable $sanitize ) {
		if ( ! warc_fs()->is_paying() ) {
			return get_option( $option, '' );
		}
		return $sanitize( $value );
	}

	/**
	 * Sanitize the negative sentiment threshold (0.0 to 1.0).
	 */
	public function sanitize_threshold( $value ): float {
		$value = (float) $value;
		return max( 0.0, min( 1.0, $value ) );
	}

	/**
	 * AJAX: Send a test invitation email to the admin.
	 */
	public function ajax_send_test_email(): void {
		check_ajax_referer( 'wairm_settings', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		$to = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		if ( empty( $to ) ) {
			$to = wp_get_current_user()->user_email;
		}

		$from_name = get_option( 'wairm_email_from_name', '' );
		if ( empty( $from_name ) ) {
			$from_name = get_bloginfo( 'name' );
		}

		$subject = get_option( 'wairm_email_subject', __( 'How was your recent purchase?', 'woo-ai-review-manager' ) );
		$subject = str_replace(
			[ '{customer_name}', '{store_name}' ],
			[ __( 'Test Customer', 'woo-ai-review-manager' ), get_bloginfo( 'name' ) ],
			$subject
		);
		$subject = '[TEST] ' . $subject;

		$body = \WooAIReviewManager\Email_Sender::build_test_email_body();

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . get_option( 'admin_email' ) . '>',
		];
		$sent    = wp_mail( $to, $subject, $body, $headers );

		if ( $sent ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => __( 'wp_mail() returned false. Check your mail configuration.', 'woo-ai-review-manager' ) ] );
		}
	}

	/**
	 * AJAX: Delete all plugin data (tables, options, cron).
	 */
	public function ajax_delete_all_data(): void {
		check_ajax_referer( 'wairm_settings', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'woo-ai-review-manager' ) ], 403 );
		}

		global $wpdb;

		// Drop custom tables.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_email_queue" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_review_invitations" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_review_sentiment" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wairm_insights" );

		// Delete plugin options.
		$options = [
			'wairm_version',
			'wairm_invitation_delay_days',
			'wairm_reminder_enabled',
			'wairm_reminder_delay_days',
			'wairm_invitation_expiry_days',
			'wairm_email_from_name',
			'wairm_email_subject',
			'wairm_email_greeting',
			'wairm_email_body_text',
			'wairm_email_button_text',
			'wairm_reminder_subject',
			'wairm_reminder_greeting',
			'wairm_reminder_body_text',
			'wairm_reminder_button_text',
			'wairm_auto_analyze',
			'wairm_auto_respond_positive',
			'wairm_negative_threshold',
			'wairm_model_preference',
			'wairm_support_email',
			'wairm_reply_as',
		];

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		// Clear scheduled cron events.
		wp_clear_scheduled_hook( 'wairm_send_review_invitations' );
		wp_clear_scheduled_hook( 'wairm_expire_invitations' );

		wp_send_json_success();
	}

	/**
	 * AJAX: Return the email HTML for iframe preview.
	 */
	public function ajax_preview_email(): void {
		check_ajax_referer( 'wairm_settings', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Permission denied.' );
		}

		// Output raw HTML for the iframe — use die() to avoid wp_die appending extra output.
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo \WooAIReviewManager\Email_Sender::build_test_email_body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- full HTML email template.
		die();
	}

	public function render_page(): void {
		$active_tab = sanitize_key( $_GET['tab'] ?? 'api' );
		$valid_tabs = [ 'api', 'email', 'general' ];
		if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
			$active_tab = 'api';
		}
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
				<?php
				$settings_group = match ( $active_tab ) {
					'email'   => 'wairm_settings_email',
					'general' => 'wairm_settings_general',
					default   => 'wairm_settings_api',
				};
				settings_fields( $settings_group );
				?>

				<?php if ( 'api' === $active_tab ) : ?>
					<?php
					$ai_available    = \WooAIReviewManager\AI_Client::is_available();
					$text_supported  = \WooAIReviewManager\AI_Client::is_text_supported();
					$providers       = \WooAIReviewManager\AI_Client::discover_providers();
					$saved_model     = get_option( 'wairm_model_preference', '' );
					?>
					<h2><?php esc_html_e( 'AI Provider', 'woo-ai-review-manager' ); ?></h2>

					<?php if ( ! $ai_available ) : ?>
						<p>
							<span class="wairm-ai-unavailable"><?php esc_html_e( 'WordPress AI Client is not available.', 'woo-ai-review-manager' ); ?></span>
							&mdash; <?php esc_html_e( 'This plugin requires WordPress 7.0 or later.', 'woo-ai-review-manager' ); ?>
						</p>
					<?php elseif ( ! $text_supported ) : ?>
						<p>
							<span class="wairm-ai-unavailable"><?php esc_html_e( 'No active AI provider.', 'woo-ai-review-manager' ); ?></span>
							&mdash;
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: link to Connectors settings */
									__( 'Configure a provider in %s.', 'woo-ai-review-manager' ),
									'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Settings &rarr; Connectors', 'woo-ai-review-manager' ) . '</a>'
								),
								[ 'a' => [ 'href' => [] ] ]
							);
							?>
						</p>
					<?php else : ?>
						<?php
						// Only show providers that are actually configured AND functional.
						$configured_providers = array_filter( $providers, static function ( $p ) {
							return $p['configured'];
						} );
						$provider_names = wp_list_pluck( $configured_providers, 'name' );
						?>
						<p>
							<span class="wairm-ai-available"><?php echo esc_html( implode( ', ', $provider_names ) ); ?></span>
							&mdash;
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: link to Connectors settings */
									__( 'Manage in %s.', 'woo-ai-review-manager' ),
									'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Settings &rarr; Connectors', 'woo-ai-review-manager' ) . '</a>'
								),
								[ 'a' => [ 'href' => [] ] ]
							);
							?>
						</p>
					<?php endif; ?>

					<?php
					if ( ! isset( $configured_providers ) ) {
						$configured_providers = [];
					}
					// Build model options: use registry models if available, otherwise static fallback.
					$static_models = [
						'anthropic' => [
							'claude-opus-4-6'   => 'Claude Opus 4.6',
							'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
							'claude-haiku-4-5'  => 'Claude Haiku 4.5',
						],
						'google' => [
							'gemini-3.1-pro'        => 'Gemini 3.1 Pro',
							'gemini-3-flash'        => 'Gemini 3 Flash',
							'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash-Lite',
						],
						'openai' => [
							'gpt-5.4'      => 'GPT-5.4',
							'gpt-5.4-mini' => 'GPT-5.4 Mini',
							'gpt-5.4-nano' => 'GPT-5.4 Nano',
							'gpt-5.3'      => 'GPT-5.3',
							'gpt-5.2'      => 'GPT-5.2',
						],
					];

					$model_options = [];
					foreach ( $configured_providers as $pid => $pdata ) {
						$models = ! empty( $pdata['models'] ) ? $pdata['models'] : ( $static_models[ $pid ] ?? [] );
						if ( ! empty( $models ) ) {
							$model_options[ $pid ] = [
								'name'   => $pdata['name'],
								'models' => $models,
							];
						}
					}
					?>

					<?php if ( $text_supported && ! empty( $configured_providers ) ) : ?>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wairm_model_preference"><?php esc_html_e( 'Model', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<?php if ( ! empty( $model_options ) ) : ?>
								<select id="wairm_model_preference" name="wairm_model_preference">
									<option value=""><?php esc_html_e( 'Automatic (first available)', 'woo-ai-review-manager' ); ?></option>
									<?php foreach ( $model_options as $pid => $pdata ) : ?>
										<optgroup label="<?php echo esc_attr( $pdata['name'] ); ?>">
											<?php foreach ( $pdata['models'] as $model_id => $model_name ) : ?>
												<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $saved_model, $model_id ); ?>>
													<?php echo esc_html( $model_name ); ?>
												</option>
											<?php endforeach; ?>
										</optgroup>
									<?php endforeach; ?>
								</select>
								<?php else : ?>
								<input type="text" id="wairm_model_preference" name="wairm_model_preference" value="<?php echo esc_attr( $saved_model ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. claude-sonnet-4-6', 'woo-ai-review-manager' ); ?>" />
								<?php endif; ?>
								<p class="description">
									<?php
									echo wp_kses(
										sprintf(
											/* translators: %s: link to Connectors settings */
											__( 'Select a model from your configured providers, or manage providers in %s.', 'woo-ai-review-manager' ),
											'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Settings &rarr; Connectors', 'woo-ai-review-manager' ) . '</a>'
										),
										[ 'a' => [ 'href' => [] ] ]
									);
									?>
								</p>
							</td>
						</tr>
					</table>
					<?php endif; ?>

					<h2><?php esc_html_e( 'Analysis', 'woo-ai-review-manager' ); ?></h2>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wairm_negative_threshold"><?php esc_html_e( 'Negative Threshold', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="number" id="wairm_negative_threshold" name="wairm_negative_threshold" value="<?php echo esc_attr( get_option( 'wairm_negative_threshold', '0.30' ) ); ?>" min="0" max="1" step="0.05" />
								<p class="description">
									<?php esc_html_e( 'Reviews scored below this value (0.0–1.0) are flagged as negative and get AI response suggestions. Default: 0.30.', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
					</table>

				<?php elseif ( 'email' === $active_tab ) : ?>

					<!-- ── Delivery ── -->
					<h2><?php esc_html_e( 'Delivery', 'woo-ai-review-manager' ); ?></h2>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wairm_email_from_name"><?php esc_html_e( 'From Name', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="text" id="wairm_email_from_name" name="wairm_email_from_name" value="<?php echo esc_attr( get_option( 'wairm_email_from_name', get_bloginfo( 'name' ) ) ); ?>" class="regular-text" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_invitation_delay_days"><?php esc_html_e( 'Send After', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="number" id="wairm_invitation_delay_days" name="wairm_invitation_delay_days" value="<?php echo esc_attr( get_option( 'wairm_invitation_delay_days', '7' ) ); ?>" min="1" max="30" />
								<span><?php esc_html_e( 'days after order completion', 'woo-ai-review-manager' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_invitation_expiry_days"><?php esc_html_e( 'Link Expiry', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="number" id="wairm_invitation_expiry_days" name="wairm_invitation_expiry_days" value="<?php echo esc_attr( get_option( 'wairm_invitation_expiry_days', '30' ) ); ?>" min="7" max="90" />
								<span><?php esc_html_e( 'days', 'woo-ai-review-manager' ); ?></span>
							</td>
						</tr>
					</table>

					<!-- ── Initial Invitation ── -->
					<h2><?php esc_html_e( 'Initial Invitation', 'woo-ai-review-manager' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Sent to customers after their order is completed. Placeholders: {customer_name}, {store_name}', 'woo-ai-review-manager' ); ?></p>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wairm_email_subject"><?php esc_html_e( 'Subject', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="text" id="wairm_email_subject" name="wairm_email_subject" value="<?php echo esc_attr( get_option( 'wairm_email_subject', __( 'How was your recent purchase?', 'woo-ai-review-manager' ) ) ); ?>" class="regular-text" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_email_greeting"><?php esc_html_e( 'Greeting', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="text" id="wairm_email_greeting" name="wairm_email_greeting" value="<?php echo esc_attr( get_option( 'wairm_email_greeting', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr__( 'Hi {customer_name}, thank you for your recent order!', 'woo-ai-review-manager' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_email_body_text"><?php esc_html_e( 'Body Text', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<textarea id="wairm_email_body_text" name="wairm_email_body_text" rows="3" class="regular-text" placeholder="<?php echo esc_attr__( 'We would love to hear what you think about the products you purchased:', 'woo-ai-review-manager' ); ?>"><?php echo esc_textarea( get_option( 'wairm_email_body_text', '' ) ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_email_button_text"><?php esc_html_e( 'Button Text', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="text" id="wairm_email_button_text" name="wairm_email_button_text" value="<?php echo esc_attr( get_option( 'wairm_email_button_text', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr__( 'Leave a Review', 'woo-ai-review-manager' ); ?>" />
							</td>
						</tr>
					</table>

					<!-- ── Reminder Email ── -->
					<h2>
						<?php esc_html_e( 'Reminder Email', 'woo-ai-review-manager' ); ?>
						<?php if ( ! warc_fs()->is_paying() ) : ?><span class="wairm-pro-badge"><?php esc_html_e( 'Pro', 'woo-ai-review-manager' ); ?></span><?php endif; ?>
					</h2>
					<p class="description"><?php esc_html_e( 'Sent to customers who haven\'t reviewed after the initial invitation. Uses same placeholders.', 'woo-ai-review-manager' ); ?></p>
					<?php if ( ! warc_fs()->is_paying() ) : ?>
						<div class="wairm-pro-gate">
							<div class="wairm-upgrade-banner">
								<p><?php esc_html_e( 'Automated follow-up reminders help increase your review conversion rate.', 'woo-ai-review-manager' ); ?></p>
								<a href="<?php echo esc_url( warc_fs()->get_upgrade_url() ); ?>" class="button"><?php esc_html_e( 'Upgrade to Pro', 'woo-ai-review-manager' ); ?></a>
							</div>
					<?php endif; ?>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wairm_reminder_enabled"><?php esc_html_e( 'Enabled', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="wairm_reminder_enabled" name="wairm_reminder_enabled" value="yes" <?php checked( get_option( 'wairm_reminder_enabled', 'yes' ), 'yes' ); ?> <?php disabled( ! warc_fs()->is_paying() ); ?> />
									<?php esc_html_e( 'Send a reminder if the customer doesn\'t review', 'woo-ai-review-manager' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_reminder_delay_days"><?php esc_html_e( 'Send After', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="number" id="wairm_reminder_delay_days" name="wairm_reminder_delay_days" value="<?php echo esc_attr( get_option( 'wairm_reminder_delay_days', '14' ) ); ?>" min="1" max="60" <?php disabled( ! warc_fs()->is_paying() ); ?> />
								<span><?php esc_html_e( 'days after initial invitation', 'woo-ai-review-manager' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_reminder_subject"><?php esc_html_e( 'Subject', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="text" id="wairm_reminder_subject" name="wairm_reminder_subject" value="<?php echo esc_attr( get_option( 'wairm_reminder_subject', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr__( 'We\'d still love to hear from you!', 'woo-ai-review-manager' ); ?>" <?php disabled( ! warc_fs()->is_paying() ); ?> />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_reminder_greeting"><?php esc_html_e( 'Greeting', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="text" id="wairm_reminder_greeting" name="wairm_reminder_greeting" value="<?php echo esc_attr( get_option( 'wairm_reminder_greeting', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr__( 'Hi {customer_name}, just a friendly reminder!', 'woo-ai-review-manager' ); ?>" <?php disabled( ! warc_fs()->is_paying() ); ?> />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_reminder_body_text"><?php esc_html_e( 'Body Text', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<textarea id="wairm_reminder_body_text" name="wairm_reminder_body_text" rows="3" class="regular-text" placeholder="<?php echo esc_attr__( 'We noticed you haven\'t had a chance to review your recent purchase yet. Your feedback helps other shoppers and helps us improve:', 'woo-ai-review-manager' ); ?>" <?php disabled( ! warc_fs()->is_paying() ); ?>><?php echo esc_textarea( get_option( 'wairm_reminder_body_text', '' ) ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_reminder_button_text"><?php esc_html_e( 'Button Text', 'woo-ai-review-manager' ); ?></label>
							</th>
							<td>
								<input type="text" id="wairm_reminder_button_text" name="wairm_reminder_button_text" value="<?php echo esc_attr( get_option( 'wairm_reminder_button_text', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr__( 'Write a Review', 'woo-ai-review-manager' ); ?>" <?php disabled( ! warc_fs()->is_paying() ); ?> />
							</td>
						</tr>
					</table>
					<?php if ( ! warc_fs()->is_paying() ) : ?>
						</div>
					<?php endif; ?>

					<!-- ── Preview & Test ── -->
					<h2><?php esc_html_e( 'Preview & Test', 'woo-ai-review-manager' ); ?></h2>
					<div class="wairm-email-preview">
						<iframe id="wairm-email-preview-frame" class="wairm-email-preview-frame"></iframe>
						<div class="wairm-test-email-form">
							<input type="email" id="wairm-test-email-address" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Recipient email', 'woo-ai-review-manager' ); ?>">
							<button type="button" class="button" id="wairm-send-test-email"><?php esc_html_e( 'Send Test Email', 'woo-ai-review-manager' ); ?></button>
							<span id="wairm-test-email-result"></span>
						</div>
					</div>

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
									<?php esc_html_e( 'When enabled, all new WooCommerce reviews will be sent to the configured AI provider for sentiment analysis.', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
						<?php if ( ! warc_fs()->is_paying() ) : ?>
						</table>
						<div class="wairm-pro-gate">
							<div class="wairm-upgrade-banner">
								<p><?php esc_html_e( 'AI response suggestions, auto-reply settings, and support email configuration are available in the Pro version.', 'woo-ai-review-manager' ); ?></p>
								<a href="<?php echo esc_url( warc_fs()->get_upgrade_url() ); ?>" class="button"><?php esc_html_e( 'Upgrade to Pro', 'woo-ai-review-manager' ); ?></a>
							</div>
							<table class="form-table">
						<?php endif; ?>
						<tr>
							<th scope="row">
								<label for="wairm_auto_respond_positive">
									<?php esc_html_e( 'Auto-respond to Positive Reviews', 'woo-ai-review-manager' ); ?>
									<?php if ( ! warc_fs()->is_paying() ) : ?><span class="wairm-pro-badge"><?php esc_html_e( 'Pro', 'woo-ai-review-manager' ); ?></span><?php endif; ?>
								</label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="wairm_auto_respond_positive" name="wairm_auto_respond_positive" value="yes" <?php checked( get_option( 'wairm_auto_respond_positive', 'no' ), 'yes' ); ?> <?php disabled( ! warc_fs()->is_paying() ); ?> />
									<?php esc_html_e( 'Automatically generate response suggestions for positive reviews', 'woo-ai-review-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'By default, only negative reviews get AI response suggestions. Enable this to get suggestions for positive reviews too.', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_reply_as">
									<?php esc_html_e( 'Post Replies As', 'woo-ai-review-manager' ); ?>
									<?php if ( ! warc_fs()->is_paying() ) : ?><span class="wairm-pro-badge"><?php esc_html_e( 'Pro', 'woo-ai-review-manager' ); ?></span><?php endif; ?>
								</label>
							</th>
							<td>
								<?php $reply_as = get_option( 'wairm_reply_as', 'store' ); ?>
								<select id="wairm_reply_as" name="wairm_reply_as" <?php disabled( ! warc_fs()->is_paying() ); ?>>
									<option value="store" <?php selected( $reply_as, 'store' ); ?>>
										<?php
										$store_label = get_option( 'wairm_email_from_name', '' );
										if ( empty( $store_label ) ) {
											$store_label = get_bloginfo( 'name' );
										}
										printf(
											/* translators: %s: store name */
											esc_html__( 'Store name (%s)', 'woo-ai-review-manager' ),
											esc_html( $store_label )
										);
										?>
									</option>
									<option value="user" <?php selected( $reply_as, 'user' ); ?>>
										<?php esc_html_e( 'Logged-in user (your personal account)', 'woo-ai-review-manager' ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Choose how reply comments appear on the product page. "Store name" uses the Email From Name setting (or site name).', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wairm_support_email">
									<?php esc_html_e( 'Support Email', 'woo-ai-review-manager' ); ?>
									<?php if ( ! warc_fs()->is_paying() ) : ?><span class="wairm-pro-badge"><?php esc_html_e( 'Pro', 'woo-ai-review-manager' ); ?></span><?php endif; ?>
								</label>
							</th>
							<td>
								<input type="email" id="wairm_support_email" name="wairm_support_email" value="<?php echo esc_attr( get_option( 'wairm_support_email', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" <?php disabled( ! warc_fs()->is_paying() ); ?> />
								<p class="description">
									<?php esc_html_e( 'Email address for customer support. The AI will reference this in response suggestions when directing customers to reach out. Defaults to the site admin email if left empty.', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
						<?php if ( ! warc_fs()->is_paying() ) : ?>
							</table>
						</div>
						<table class="form-table">
						<?php endif; ?>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Data & Privacy', 'woo-ai-review-manager' ); ?>
							</th>
							<td>
								<p>
									<?php esc_html_e( 'This plugin sends review text to the AI provider configured in your WordPress Connectors for sentiment analysis and response generation.', 'woo-ai-review-manager' ); ?>
								</p>
								<p>
									<?php esc_html_e( 'Please review the privacy policy of your configured AI provider for details on how they handle your data.', 'woo-ai-review-manager' ); ?>
								</p>
								<p>
									<?php esc_html_e( 'No customer personally identifiable information (PII) is sent to the AI provider—only the review text and product name.', 'woo-ai-review-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Delete All Data', 'woo-ai-review-manager' ); ?>
							</th>
							<td>
								<p>
									<?php esc_html_e( 'Remove all plugin data including sentiment records, invitations, email queue, insights, and settings. This cannot be undone.', 'woo-ai-review-manager' ); ?>
								</p>
								<p style="margin-top: 10px;">
									<button type="button" class="button button-link-delete" id="wairm-delete-all-data">
										<?php esc_html_e( 'Delete All Data', 'woo-ai-review-manager' ); ?>
									</button>
									<span id="wairm-delete-result"></span>
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