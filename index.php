<?php
/**
 * Plugin Name: DuweWP Disable Gutenberg Blocks
 * Plugin URI:  https://example.com/duwewp-disable-gutenberg-blocks
 * Description: Restrict available Gutenberg blocks site-wide with an admin settings page and a lightweight editor script.
 * Version:     1.1.0
 * Author:      DuweWP
 * Text Domain: duwewp-disable-gutenberg-blocks
 * License:     GPL-2.0+
 */

declare( strict_types=1 );

namespace DuweWP\DisableGutenbergBlocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Main plugin class.
 */
final class Plugin {

	public const VERSION = '1.1.0';

	private const OPTION_ALLOWED_BLOCKS = 'duwewp_allowed_blocks';

	private const DEFAULT_ALLOWED_BLOCKS = [
		'core/paragraph',
		'core/image',
		'core/heading',
		'core/list',
	];

	private static ?Plugin $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// Textdomain.
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// Server-side block restriction.
		add_filter( 'allowed_block_types_all', [ $this, 'filter_allowed_block_types' ], 10, 2 );

		// Plugin settings link.
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'plugin_action_links' ] );

		// Admin pages and settings.
		add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		// Lightweight editor script (client-side visibility only).
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_script' ] );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'duwewp-disable-gutenberg-blocks', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Filter allowed blocks server-side.
	 *
	 * @param bool|array $allowed Allowed block types or boolean.
	 * @param \WP_Post|null $post Current post object or null.
	 *
	 * @return bool|array
	 */
	public function filter_allowed_block_types( $allowed, $post ) {
		// If a theme or another plugin has explicitly disabled blocks, respect it.
		if ( false === $allowed ) {
			return false;
		}

		$option = get_option( self::OPTION_ALLOWED_BLOCKS );

		if ( is_array( $option ) && ! empty( $option ) ) {
			$sanitized = array_filter( array_map( 'sanitize_text_field', $option ), static function ( $name ) {
				return (bool) preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name );
			} );

			if ( ! empty( $sanitized ) ) {
				return array_values( $sanitized );
			}
		}

		// Fall back to default whitelist.
		return self::DEFAULT_ALLOWED_BLOCKS;
	}

	/**
	 * Add a Settings link on the plugins page.
	 *
	 * @param array $links Existing plugin action links.
	 *
	 * @return array
	 */
	public function plugin_action_links( array $links ): array {
		$settings_url  = esc_url( admin_url( 'options-general.php?page=duwewp-blocks' ) );
		$settings_link = '<a href="' . $settings_url . '">' . esc_html__( 'Settings', 'duwewp-disable-gutenberg-blocks' ) . '</a>';

		array_unshift( $links, $settings_link );

		return $links;
	}

	/* ------------------------------------------------------------------
	 * Admin: Settings Page & Registration
	 * ------------------------------------------------------------------ */

	public function register_settings_page(): void {
		add_options_page(
			/* page title */ __( 'Allowed Gutenberg Blocks', 'duwewp-disable-gutenberg-blocks' ),
			/* menu title */ __( 'Allowed Blocks', 'duwewp-disable-gutenberg-blocks' ),
			/* capability */ 'manage_options',
			/* menu slug  */ 'duwewp-blocks',
			/* callback   */ [ $this, 'render_settings_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			'duwewp_blocks_group',
			self::OPTION_ALLOWED_BLOCKS,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_blocks_setting' ],
				'default'           => self::DEFAULT_ALLOWED_BLOCKS,
			]
		);
	}

	/**
	 * Sanitize the textarea input storing one block name per line.
	 *
	 * @param mixed $input Raw input from the settings page.
	 *
	 * @return array
	 */
	public function sanitize_blocks_setting( $input ): array {
		if ( is_string( $input ) ) {
			// If a string was saved (old data), split by newlines.
			$lines = preg_split( '/\r\n|\r|\n/', $input );
		} elseif ( is_array( $input ) ) {
			// Some WP setups may POST as array; normalize to strings.
			$lines = $input;
		} else {
			return [];
		}

		$lines = array_map( 'trim', $lines );
		$lines = array_filter( $lines, static function ( $line ) {
			return '' !== $line;
		} );

		$sanitized = array_filter( array_map( 'sanitize_text_field', $lines ), static function ( $name ) {
			return (bool) preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name );
		} );

		return array_values( $sanitized );
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		/** @var array $blocks */
		$blocks = get_option( self::OPTION_ALLOWED_BLOCKS, self::DEFAULT_ALLOWED_BLOCKS );

		// Convert to newline separated list for textarea.
		$value = implode( "\n", array_map( 'strval', (array) $blocks ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Allowed Gutenberg Blocks', 'duwewp-disable-gutenberg-blocks' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'duwewp_blocks_group' );
				do_settings_sections( 'duwewp-blocks' );
				?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::OPTION_ALLOWED_BLOCKS ); ?>"><?php esc_html_e( 'Block names (one per line)', 'duwewp-disable-gutenberg-blocks' ); ?></label>
						</th>
						<td>
							<textarea id="<?php echo esc_attr( self::OPTION_ALLOWED_BLOCKS ); ?>" name="<?php echo esc_attr( self::OPTION_ALLOWED_BLOCKS ); ?>" rows="10" cols="50" class="large-text code"><?php echo esc_textarea( $value ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Enter block names one per line, e.g., core/paragraph', 'duwewp-disable-gutenberg-blocks' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Lightweight editor script - server only adds small inline script
	 * that logs allowed blocks for debugging/visibility in browser console.
	 * ------------------------------------------------------------------ */
	public function enqueue_editor_script(): void {
		// Only enqueue in the block editor context.
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return;
		}

		$allowed = get_option( self::OPTION_ALLOWED_BLOCKS, self::DEFAULT_ALLOWED_BLOCKS );

		// Register an empty script so we can add inline script data without files.
		wp_register_script( 'duwewp-editor-info', false, [], self::VERSION, true );

		$inline = 'console.info( "DuweWP allowed blocks:", ' . wp_json_encode( array_values( (array) $allowed ) ) . ' );';

		wp_add_inline_script( 'duwewp-editor-info', $inline );
		wp_enqueue_script( 'duwewp-editor-info' );
	}
}

// Boot the plugin.
Plugin::instance();
