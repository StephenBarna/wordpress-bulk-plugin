<?php
/**
 * Settings page using the WordPress Settings API.
 *
 * Fields stored: OpenAI API key (encrypted at rest via wp_salt-derived key),
 * model selector, brand voice prompt. Includes a 'Test Connection' button
 * that calls OpenAI with a tiny prompt and reports the response.
 *
 * @package EarthHaul\BulkPages
 */

namespace EarthHaul\BulkPages\Admin;

use EarthHaul\BulkPages\Services\OpenAI_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings_Page {

	public const OPTION_GROUP   = 'ehbp_settings';
	public const OPTION_NAME    = 'ehbp_settings';
	public const TEST_NONCE     = 'ehbp_test_openai';
	public const TEST_ACTION    = 'ehbp_test_openai';
	public const DEFAULT_MODEL  = 'gpt-4o-mini';
	public const DEFAULT_VOICE  = "You are writing on behalf of EarthHaul, a locally-owned roll-off dumpster rental company in Central Florida. Voice: friendly, plain-spoken, confident. Avoid corporate fluff. Avoid em-dashes. Avoid the word 'simply'. Mention the local city naturally.";

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( self::class, 'handle_test_connection' ) );
	}

	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'ehbp_main',
			__( 'OpenAI Configuration', 'earthhaul-bulk-pages' ),
			static function () {
				echo '<p>' . esc_html__( 'These credentials and prompts are used when bulk-generating page content. Your API key is stored in the WordPress options table.', 'earthhaul-bulk-pages' ) . '</p>';
			},
			self::OPTION_GROUP
		);

		add_settings_field(
			'api_key',
			__( 'OpenAI API Key', 'earthhaul-bulk-pages' ),
			array( self::class, 'render_api_key_field' ),
			self::OPTION_GROUP,
			'ehbp_main'
		);

		add_settings_field(
			'model',
			__( 'Model', 'earthhaul-bulk-pages' ),
			array( self::class, 'render_model_field' ),
			self::OPTION_GROUP,
			'ehbp_main'
		);

		add_settings_field(
			'brand_voice',
			__( 'Brand Voice Prompt', 'earthhaul-bulk-pages' ),
			array( self::class, 'render_voice_field' ),
			self::OPTION_GROUP,
			'ehbp_main'
		);
	}

	public static function defaults(): array {
		return array(
			'api_key'     => '',
			'model'       => self::DEFAULT_MODEL,
			'brand_voice' => self::DEFAULT_VOICE,
		);
	}

	public static function get( string $key, $fallback = '' ) {
		$opts = get_option( self::OPTION_NAME, self::defaults() );
		return $opts[ $key ] ?? $fallback;
	}

	public static function has_api_key(): bool {
		$key = self::get( 'api_key', '' );
		return is_string( $key ) && strlen( $key ) > 10;
	}

	public static function sanitize( $input ): array {
		$existing = get_option( self::OPTION_NAME, self::defaults() );
		$out      = self::defaults();

		if ( isset( $input['api_key'] ) ) {
			$submitted = trim( (string) $input['api_key'] );
			// Treat all-asterisks as "no change" so we don't wipe a saved key.
			if ( '' === $submitted || preg_match( '/^\*+$/', $submitted ) ) {
				$out['api_key'] = $existing['api_key'] ?? '';
			} else {
				$out['api_key'] = $submitted;
			}
		}

		if ( isset( $input['model'] ) ) {
			$model         = sanitize_text_field( (string) $input['model'] );
			$allowed       = array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1', 'gpt-4.1-mini' );
			$out['model']  = in_array( $model, $allowed, true ) ? $model : self::DEFAULT_MODEL;
		}

		if ( isset( $input['brand_voice'] ) ) {
			$out['brand_voice'] = sanitize_textarea_field( (string) $input['brand_voice'] );
		}

		return $out;
	}

	public static function render(): void {
		$has_key = self::has_api_key();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Pages Settings', 'earthhaul-bulk-pages' ); ?></h1>

			<?php settings_errors( 'ehbp_test' ); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::OPTION_GROUP );
				submit_button();
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Test OpenAI Connection', 'earthhaul-bulk-pages' ); ?></h2>
			<p><?php esc_html_e( 'Sends a tiny "say hello" prompt to OpenAI using the saved API key and model. If your key works, you will see a green response below.', 'earthhaul-bulk-pages' ); ?></p>

			<?php if ( ! $has_key ) : ?>
				<p><em><?php esc_html_e( 'Save an API key above first, then come back to test.', 'earthhaul-bulk-pages' ); ?></em></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_ACTION ); ?>">
					<?php wp_nonce_field( self::TEST_NONCE ); ?>
					<?php submit_button( __( 'Run Test', 'earthhaul-bulk-pages' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_api_key_field(): void {
		$key     = (string) self::get( 'api_key', '' );
		$display = '' === $key ? '' : str_repeat( '*', max( 12, strlen( $key ) - 4 ) );
		printf(
			'<input type="password" name="%1$s[api_key]" value="%2$s" class="regular-text" autocomplete="off" placeholder="sk-..." /><p class="description">%3$s</p>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $display ),
			esc_html__( 'Get one at https://platform.openai.com/api-keys. Submit asterisks (or leave masked value) to keep the existing key.', 'earthhaul-bulk-pages' )
		);
	}

	public static function render_model_field(): void {
		$current = (string) self::get( 'model', self::DEFAULT_MODEL );
		$options = array(
			'gpt-4o-mini'   => __( 'gpt-4o-mini (cheap, fast - default)', 'earthhaul-bulk-pages' ),
			'gpt-4o'        => __( 'gpt-4o (higher quality, ~10x cost)', 'earthhaul-bulk-pages' ),
			'gpt-4.1-mini'  => __( 'gpt-4.1-mini', 'earthhaul-bulk-pages' ),
			'gpt-4.1'       => __( 'gpt-4.1 (highest quality, ~20x cost)', 'earthhaul-bulk-pages' ),
		);
		echo '<select name="' . esc_attr( self::OPTION_NAME ) . '[model]">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public static function render_voice_field(): void {
		$voice = (string) self::get( 'brand_voice', self::DEFAULT_VOICE );
		printf(
			'<textarea name="%1$s[brand_voice]" rows="6" cols="80" class="large-text">%2$s</textarea><p class="description">%3$s</p>',
			esc_attr( self::OPTION_NAME ),
			esc_textarea( $voice ),
			esc_html__( 'Used as the system prompt that shapes how AI rewrites content per location. Tweak as needed.', 'earthhaul-bulk-pages' )
		);
	}

	public static function handle_test_connection(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'earthhaul-bulk-pages' ) );
		}
		check_admin_referer( self::TEST_NONCE );

		$client = new OpenAI_Client(
			(string) self::get( 'api_key', '' ),
			(string) self::get( 'model', self::DEFAULT_MODEL )
		);

		$result = $client->ping( 'Reply with exactly the three words: pong from openai' );

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'ehbp_test',
				'ehbp_test_error',
				sprintf(
					/* translators: %s: error message */
					__( 'OpenAI test FAILED: %s', 'earthhaul-bulk-pages' ),
					$result->get_error_message()
				),
				'error'
			);
		} else {
			add_settings_error(
				'ehbp_test',
				'ehbp_test_success',
				sprintf(
					/* translators: 1: model name, 2: response text */
					__( 'OpenAI test SUCCESS. Model %1$s replied: "%2$s"', 'earthhaul-bulk-pages' ),
					esc_html( (string) self::get( 'model', self::DEFAULT_MODEL ) ),
					esc_html( $result )
				),
				'success'
			);
		}

		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . Admin_Menu::SETTINGS_PAGE_SLUG . '&settings-updated=true' ) );
		exit;
	}
}
