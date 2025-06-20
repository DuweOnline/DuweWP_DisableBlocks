<?php
/*
Plugin Name:  DuweWP Disable Gutenberg Blocks
Plugin URI:   https://github.com/DuweOnline/DuweWP_DisableBlocks
Description:  Adds an options page to disable selected Gutenberg blocks.
Version:      1.0.0
Author:       Duwe Online
Author URI:   https://duwe.co.uk
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  DuweWP_DisableBlocks
Domain Path:  /languages
*/

if (!defined('ABSPATH')) exit;

function duwewp_disableblock_scripts() {
	wp_enqueue_style( 'selectcss2', plugin_dir_url(__FILE__) . '/scripts/select2.min.css', false, '1.0.0');
	wp_enqueue_style( 'duwewp_db_css', plugin_dir_url(__FILE__) . '/scripts/duwewp_dgb.css', false, '1.0.0');
	wp_enqueue_script( 'select2js', plugin_dir_url(__FILE__) . '/scripts/select2.min.js', ['jquery'], '1.0.0', true );
	wp_enqueue_script( 'duwewp_db_selector', plugin_dir_url(__FILE__) . '/scripts/duwewp_db_selector.js', ['jquery'], '1.0.0', true );

};
add_action( 'admin_enqueue_scripts', 'duwewp_disableblock_scripts' );
function duwewp_disableblocks_register_settings() {
	register_setting(
		'duwewp_disableblocks_settings_group',
		'duwewp_disableblocks_disabled_blocks',
		[
			'type' => 'array',
			'sanitize_callback' => 'duwewp_disableblocks_sanitize_blocks',
			'default' => [],
		]
	);
}
add_action('admin_init', 'duwewp_disableblocks_register_settings');
add_action('rest_api_init', function () {
	register_rest_route('duwewp/v1', '/blocks', [
		'methods' => 'GET',
		'callback' => 'duwewp_disableblocks_get_registered_blocks',
		'permission_callback' => function () {
			return current_user_can('manage_options');
		}
	]);
});
function duwewp_disableblocks_get_registered_blocks() {
	$blocks = \WP_Block_Type_Registry::get_instance()->get_all_registered();
	$block_list = [];
	foreach ($blocks as $block_name => $block_obj) {
		$block_list[] = [
			'name' => $block_name,
			'title' => isset($block_obj->title) ? $block_obj->title : $block_name
		];
	}
	return rest_ensure_response($block_list);
}
function duwewp_disableblocks_localize_nonce($hook) {
	if ($hook !== 'settings_page_disable-gutenberg-blocks') return;
	wp_enqueue_script('wp-api'); 
	wp_localize_script('wp-api', 'duwewpDisableBlocks', [
		'nonce' => wp_create_nonce('wp_rest'),
	]);
}
add_action('admin_enqueue_scripts', 'duwewp_disableblocks_localize_nonce');
function duwewp_disableblocks_sanitize_blocks($input) {
	if (!is_array($input)) return [];
	return array_filter(array_map('sanitize_text_field', $input));
}
function duwewp_disableblocks_add_options_page() {
	add_options_page(
		esc_html__('DuweWP Disable Gutenberg Blocks', 'DuweWP_DisableBlocks'),
		esc_html__('DuweWP Disable Blocks', 'DuweWP_DisableBlocks'),
		'manage_options',
		'disable-gutenberg-blocks',
		'duwewp_disableblocks_render_options_page'
	);
}
add_action('admin_menu', 'duwewp_disableblocks_add_options_page');
function duwewp_disableblocks_render_options_page() {
	$disabled_blocks = get_option('duwewp_disableblocks_disabled_blocks', []);
	$disabled_blocks = is_array($disabled_blocks) ? array_map('sanitize_text_field', $disabled_blocks) : [];
	?>
	<div class="wrap">
		<div class="branding_sections">
			<div id="duweavatar">
				<a href="https://duwe.co.uk" target="_blank" rel="noopener noreferrer"><img src="<?php echo esc_url_raw(plugin_dir_url(__FILE__)); ?>img/duwe.png" class="duweavatar" alt="Duwe Online"></a>
			</div>
			<div class="duwecontent">
				<h1><?php esc_html_e('DuweWP Disable Gutenberg Blocks', 'DuweWP_DisableBlocks'); ?></h1>
				<p><?php esc_html_e('This page allows you to completely disable any Gutenberg Blocks that are registered for this site.', 'DuweWP_DisableBlocks'); ?></p>
				<p><?php esc_html_e('Use with caution as posts and pages may already feature these blocks and layouts could break.', 'DuweWP_DisableBlocks'); ?></p>
			</div>
		</div>
		<form method="post" action="options.php">
			<?php
				settings_fields('duwewp_disableblocks_settings_group');
			?>
			<label for="duwewp_disableblocks_block_selector"><?php esc_html_e('Select blocks to disable:', 'DuweWP_DisableBlocks'); ?></label>
			<select multiple id="duwewp_disableblocks_block_selector" name="duwewp_disableblocks_disabled_blocks[]" style="width: 100%; height: 300px;">
			</select>
			<?php submit_button(); ?>
		</form>
		<p style="margin-top: 40px; font-size: 0.9em;">
			&copy; <?php echo esc_html(gmdate('Y')); ?> <a href="https://duwe.co.uk" target="_blank" rel="noopener noreferrer">Duwe Online</a>. All rights reserved.
		</p>
	</div>
	<script>
	document.addEventListener('DOMContentLoaded', async () => {
		const select = document.getElementById('duwewp_disableblocks_block_selector');
		const selected = <?php echo json_encode(array_map('esc_js', $disabled_blocks)); ?>;
		try {
			const res = await fetch('<?php echo esc_url_raw(rest_url('duwewp/v1/blocks')); ?>', {
				headers: {
					'X-WP-Nonce': duwewpDisableBlocks.nonce
				}
			});
			if (!res.ok) throw new Error('Failed to load blocks.');
			const blocks = await res.json();
			blocks.forEach(block => {
				const option = document.createElement('option');
				option.value = block.name;
				option.textContent = `${block.name} (${block.title})`;
				if (selected.includes(block.name)) {
					option.selected = true;
				}
				select.appendChild(option);
			});
		} catch (error) {
			console.error('Error loading block list:', error);
		}
	});
	</script>
	<?php
}
function duwewp_disableblocks_disable_selected_blocks() {
	$disabled_blocks = get_option('duwewp_disableblocks_disabled_blocks', []);
	if (!is_array($disabled_blocks) || empty($disabled_blocks)) return;
	wp_register_script(
		'duwewp-disable-blocks',
		'',
		['wp-blocks', 'wp-dom-ready'],
		null,
		true
	);
	wp_add_inline_script('duwewp-disable-blocks', duwewp_disableblocks_generate_inline_script($disabled_blocks));
	wp_enqueue_script('duwewp-disable-blocks');
}
add_action('enqueue_block_editor_assets', 'duwewp_disableblocks_disable_selected_blocks');

function duwewp_disableblocks_generate_inline_script($blocks) {
	$sanitized = array_map('sanitize_text_field', $blocks);
	$js_array = json_encode($sanitized);

	$script  = "wp.domReady(function () {";
	$script .= "const disabledBlocks = $js_array;";
	$script .= "disabledBlocks.forEach(function(blockName) {";
	$script .= "if (wp.blocks.getBlockType(blockName)) {";
	$script .= "wp.blocks.unregisterBlockType(blockName);";
	$script .= "}";
	$script .= "});";
	$script .= "});";

	return $script;
}
