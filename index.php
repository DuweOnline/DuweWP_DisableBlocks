<?php
/*
Plugin Name:  DuweWP Disable Gutenberg Blocks
Plugin URI:   https://github.com/DuweOnline/DuweWP_DisableBlocks
Description:  Adds an options page to disable selected Gutenberg blocks.
Version:      1.0.1
Author:       Duwe Online
Author URI:   https://duwe.co.uk
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  DuweWP_DisableBlocks
Domain Path:  /languages
*/

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('DUWEWP_DB_VERSION', '1.0.1');
define('DUWEWP_DB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DUWEWP_DB_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Plugin activation hook
 */
function duwewp_disableblocks_activate() {
	// Add default options if they don't exist
	if (false === get_option('duwewp_disableblocks_disabled_blocks')) {
		add_option('duwewp_disableblocks_disabled_blocks', []);
	}
}
register_activation_hook(__FILE__, 'duwewp_disableblocks_activate');

/**
 * Plugin deactivation hook
 */
function duwewp_disableblocks_deactivate() {
	// Clean up any temporary data if needed
	wp_cache_delete('duwewp_disabled_blocks');
}
register_deactivation_hook(__FILE__, 'duwewp_disableblocks_deactivate');

/**
 * Load plugin text domain for translations
 */
function duwewp_disableblocks_load_textdomain() {
	load_plugin_textdomain(
		'DuweWP_DisableBlocks',
		false,
		dirname(plugin_basename(__FILE__)) . '/languages'
	);
}
add_action('plugins_loaded', 'duwewp_disableblocks_load_textdomain');

/**
 * Enqueue scripts and styles only on the plugin's admin page
 */
function duwewp_disableblocks_admin_scripts($hook) {
	// Only load on our settings page
	if ($hook !== 'settings_page_disable-gutenberg-blocks') {
		return;
	}

	// Check if files exist before enqueueing
	$select2_css = DUWEWP_DB_PLUGIN_PATH . 'scripts/select2.min.css';
	$custom_css = DUWEWP_DB_PLUGIN_PATH . 'scripts/duwewp_dgb.css';
	$select2_js = DUWEWP_DB_PLUGIN_PATH . 'scripts/select2.min.js';
	$custom_js = DUWEWP_DB_PLUGIN_PATH . 'scripts/duwewp_db_selector.js';

	// Enqueue styles
	if (file_exists($select2_css)) {
		wp_enqueue_style(
			'duwewp-select2-css',
			DUWEWP_DB_PLUGIN_URL . 'scripts/select2.min.css',
			[],
			DUWEWP_DB_VERSION
		);
	}

	if (file_exists($custom_css)) {
		wp_enqueue_style(
			'duwewp-db-css',
			DUWEWP_DB_PLUGIN_URL . 'scripts/duwewp_dgb.css',
			[],
			DUWEWP_DB_VERSION
		);
	}

	// Enqueue scripts
	if (file_exists($select2_js)) {
		wp_enqueue_script(
			'duwewp-select2-js',
			DUWEWP_DB_PLUGIN_URL . 'scripts/select2.min.js',
			['jquery'],
			DUWEWP_DB_VERSION,
			true
		);
	}

	if (file_exists($custom_js)) {
		wp_enqueue_script(
			'duwewp-db-selector',
			DUWEWP_DB_PLUGIN_URL . 'scripts/duwewp_db_selector.js',
			['jquery', 'duwewp-select2-js'],
			DUWEWP_DB_VERSION,
			true
		);
	}

	// Enqueue WordPress API script
	wp_enqueue_script('wp-api');

	// Localize script with nonce and other data
	wp_localize_script('wp-api', 'duwewpDisableBlocks', [
		'nonce' => wp_create_nonce('wp_rest'),
		'apiUrl' => rest_url('duwewp/v1/blocks'),
		'strings' => [
			'loading' => __('Loading blocks...', 'DuweWP_DisableBlocks'),
			'error' => __('Error loading blocks. Please refresh the page.', 'DuweWP_DisableBlocks'),
			'placeholder' => __('Select blocks to disable...', 'DuweWP_DisableBlocks'),
		]
	]);
}
add_action('admin_enqueue_scripts', 'duwewp_disableblocks_admin_scripts');

/**
 * Register plugin settings
 */
function duwewp_disableblocks_register_settings() {
	register_setting(
		'duwewp_disableblocks_settings_group',
		'duwewp_disableblocks_disabled_blocks',
		[
			'type' => 'array',
			'sanitize_callback' => 'duwewp_disableblocks_sanitize_blocks',
			'default' => [],
			'show_in_rest' => false,
		]
	);
}
add_action('admin_init', 'duwewp_disableblocks_register_settings');

/**
 * Register REST API endpoint for fetching blocks
 */
function duwewp_disableblocks_register_rest_routes() {
	register_rest_route('duwewp/v1', '/blocks', [
		'methods' => 'GET',
		'callback' => 'duwewp_disableblocks_get_registered_blocks',
		'permission_callback' => 'duwewp_disableblocks_rest_permission_check',
		'args' => [
			'search' => [
				'description' => __('Search term to filter blocks', 'DuweWP_DisableBlocks'),
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		],
	]);
}
add_action('rest_api_init', 'duwewp_disableblocks_register_rest_routes');

/**
 * Permission callback for REST API
 */
function duwewp_disableblocks_rest_permission_check() {
	return current_user_can('manage_options');
}

/**
 * Get all registered blocks via REST API
 */
function duwewp_disableblocks_get_registered_blocks($request) {
	try {
		$search = $request->get_param('search');
		$blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();
		$block_list = [];

		foreach ($blocks as $block_name => $block_obj) {
			// Filter by search term if provided
			if ($search && stripos($block_name, $search) === false && 
				stripos($block_obj->title ?? '', $search) === false) {
				continue;
			}

			$block_list[] = [
				'name' => $block_name,
				'title' => isset($block_obj->title) && !empty($block_obj->title) ? $block_obj->title : $block_name,
				'category' => $block_obj->category ?? 'common',
				'icon' => $block_obj->icon ?? null,
			];
		}

		// Sort blocks alphabetically by name
		usort($block_list, function($a, $b) {
			return strcmp($a['name'], $b['name']);
		});

		return rest_ensure_response($block_list);

	} catch (Exception $e) {
		return new WP_Error(
			'blocks_error',
			__('Failed to retrieve blocks', 'DuweWP_DisableBlocks'),
			['status' => 500]
		);
	}
}

/**
 * Sanitize disabled blocks array
 */
function duwewp_disableblocks_sanitize_blocks($input) {
	if (!is_array($input)) {
		return [];
	}

	// Remove empty values and sanitize
	$sanitized = array_filter(array_map('sanitize_text_field', $input));
	
	// Remove duplicates
	return array_unique($sanitized);
}

/**
 * Add options page to admin menu
 */
function duwewp_disableblocks_add_options_page() {
	add_options_page(
		__('DuweWP Disable Gutenberg Blocks', 'DuweWP_DisableBlocks'),
		__('DuweWP Disable Blocks', 'DuweWP_DisableBlocks'),
		'manage_options',
		'disable-gutenberg-blocks',
		'duwewp_disableblocks_render_options_page'
	);
}
add_action('admin_menu', 'duwewp_disableblocks_add_options_page');

/**
 * Render the options page
 */
function duwewp_disableblocks_render_options_page() {
	// Check user permissions
	if (!current_user_can('manage_options')) {
		wp_die(__('You do not have sufficient permissions to access this page.', 'DuweWP_DisableBlocks'));
	}

	// Handle form submission and show messages
	$message = '';
	if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
		$message = '<div class="notice notice-success is-dismissible"><p>' . 
				   __('Settings saved successfully!', 'DuweWP_DisableBlocks') . 
				   '</p></div>';
	}

	$disabled_blocks = get_option('duwewp_disableblocks_disabled_blocks', []);
	$disabled_blocks = is_array($disabled_blocks) ? array_map('sanitize_text_field', $disabled_blocks) : [];
	?>
	<div class="wrap">
		<?php echo $message; ?>
		
		<div class="branding_sections">
			<div id="duweavatar">
				<a href="https://duwe.co.uk" target="_blank" rel="noopener noreferrer">
					<img src="<?php echo esc_url(DUWEWP_DB_PLUGIN_URL . 'img/duwe.png'); ?>" 
						 class="duweavatar" 
						 alt="<?php esc_attr_e('Duwe Online', 'DuweWP_DisableBlocks'); ?>">
				</a>
			</div>
			<div class="duwecontent">
				<h1><?php esc_html_e('DuweWP Disable Gutenberg Blocks', 'DuweWP_DisableBlocks'); ?></h1>
				<p><?php esc_html_e('This page allows you to completely disable any Gutenberg Blocks that are registered for this site.', 'DuweWP_DisableBlocks'); ?></p>
				<div class="notice notice-warning inline">
					<p><strong><?php esc_html_e('Warning:', 'DuweWP_DisableBlocks'); ?></strong> 
					   <?php esc_html_e('Use with caution as posts and pages may already feature these blocks and layouts could break.', 'DuweWP_DisableBlocks'); ?>
					</p>
				</div>
			</div>
		</div>

		<form method="post" action="options.php" id="disable-blocks-form">
			<?php
			settings_fields('duwewp_disableblocks_settings_group');
			do_settings_sections('duwewp_disableblocks_settings_group');
			?>
			
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="duwewp_disableblocks_block_selector">
							<?php esc_html_e('Select blocks to disable:', 'DuweWP_DisableBlocks'); ?>
						</label>
					</th>
					<td>
						<select multiple 
								id="duwewp_disableblocks_block_selector" 
								name="duwewp_disableblocks_disabled_blocks[]" 
								style="width: 100%; min-height: 300px;">
							<option value=""><?php esc_html_e('Loading blocks...', 'DuweWP_DisableBlocks'); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e('Hold Ctrl (or Cmd on Mac) to select multiple blocks. You can also use the search functionality to find specific blocks.', 'DuweWP_DisableBlocks'); ?>
						</p>
						<div id="block-count-info" style="margin-top: 10px; font-style: italic; display: none;">
							<span id="selected-count">0</span> <?php esc_html_e('blocks selected', 'DuweWP_DisableBlocks'); ?>
						</div>
					</td>
				</tr>
			</table>

			<?php submit_button(__('Save Changes', 'DuweWP_DisableBlocks')); ?>
		</form>

		<div class="duwewp-info-section" style="margin-top: 40px; padding: 20px; background: #f9f9f9; border-left: 4px solid #00a0d2;">
			<h3><?php esc_html_e('Currently Disabled Blocks', 'DuweWP_DisableBlocks'); ?></h3>
			<?php if (!empty($disabled_blocks)): ?>
				<ul style="list-style-type: disc; margin-left: 20px;">
					<?php foreach ($disabled_blocks as $block): ?>
						<li><code><?php echo esc_html($block); ?></code></li>
					<?php endforeach; ?>
				</ul>
			<?php else: ?>
				<p><?php esc_html_e('No blocks are currently disabled.', 'DuweWP_DisableBlocks'); ?></p>
			<?php endif; ?>
		</div>

		<p style="margin-top: 40px; font-size: 0.9em; color: #666;">
			&copy; <?php echo esc_html(gmdate('Y')); ?> 
			<a href="https://duwe.co.uk" target="_blank" rel="noopener noreferrer">Duwe Online</a>. 
			<?php esc_html_e('All rights reserved.', 'DuweWP_DisableBlocks'); ?>
		</p>
	</div>

	<script>
	document.addEventListener('DOMContentLoaded', async function() {
		const select = document.getElementById('duwewp_disableblocks_block_selector');
		const countInfo = document.getElementById('block-count-info');
		const selectedCount = document.getElementById('selected-count');
		const form = document.getElementById('disable-blocks-form');
		const submitButton = form.querySelector('input[type="submit"]');
		
		// Previously selected blocks
		const selectedBlocks = <?php echo wp_json_encode($disabled_blocks); ?>;
		
		// Show loading state
		select.innerHTML = '<option value="">' + (window.duwewpDisableBlocks?.strings?.loading || 'Loading blocks...') + '</option>';
		
		try {
			const response = await fetch(window.duwewpDisableBlocks?.apiUrl || '<?php echo esc_url(rest_url('duwewp/v1/blocks')); ?>', {
				method: 'GET',
				headers: {
					'X-WP-Nonce': window.duwewpDisableBlocks?.nonce || '',
					'Content-Type': 'application/json',
				},
				credentials: 'same-origin'
			});

			if (!response.ok) {
				throw new Error('HTTP ' + response.status + ': ' + response.statusText);
			}

			const blocks = await response.json();
			
			// Clear loading option
			select.innerHTML = '';
			
			// Group blocks by category
			const groupedBlocks = {};
			blocks.forEach(function(block) {
				const category = block.category || 'common';
				if (!groupedBlocks[category]) {
					groupedBlocks[category] = [];
				}
				groupedBlocks[category].push(block);
			});
			
			// Add options grouped by category
			Object.keys(groupedBlocks).sort().forEach(function(category) {
				const optgroup = document.createElement('optgroup');
				optgroup.label = category.charAt(0).toUpperCase() + category.slice(1);
				
				groupedBlocks[category].forEach(function(block) {
					const option = document.createElement('option');
					option.value = block.name;
					option.textContent = block.name + ' (' + block.title + ')';
					
					if (selectedBlocks.includes(block.name)) {
						option.selected = true;
					}
					
					optgroup.appendChild(option);
				});
				
				select.appendChild(optgroup);
			});
			
			// Initialize Select2 if available
			if (typeof jQuery !== 'undefined' && jQuery.fn.select2) {
				jQuery(select).select2({
					placeholder: window.duwewpDisableBlocks?.strings?.placeholder || 'Select blocks to disable...',
					allowClear: true,
					width: '100%',
					templateResult: function(option) {
						if (!option.id) return option.text;
						
						const $result = jQuery('<span></span>');
						$result.text(option.text);
						
						// Add a small indicator for core blocks
						if (option.id.startsWith('core/')) {
							$result.append(' <small style="color: #666;">[Core]</small>');
						}
						
						return $result;
					}
				});
				
				// Update count when selection changes
				jQuery(select).on('change', updateSelectedCount);
			} else {
				// Fallback for when Select2 is not available
				select.addEventListener('change', updateSelectedCount);
			}
			
			// Initial count update and show counter
			updateSelectedCount();
			countInfo.style.display = 'block';
			
		} catch (error) {
			console.error('Error loading block list:', error);
			select.innerHTML = '<option value="">' + 
				(window.duwewpDisableBlocks?.strings?.error || 'Error loading blocks. Please refresh the page.') + 
				'</option>';
			
			// Show error message to user
			const errorDiv = document.createElement('div');
			errorDiv.className = 'notice notice-error inline';
			errorDiv.innerHTML = '<p><strong>Error:</strong> ' + error.message + '</p>';
			select.parentNode.insertBefore(errorDiv, select);
		}
		
		function updateSelectedCount() {
			const selected = Array.from(select.selectedOptions).length;
			selectedCount.textContent = selected;
			
			// Update submit button text
			if (selected > 0) {
				submitButton.value = '<?php esc_attr_e('Save Changes', 'DuweWP_DisableBlocks'); ?>' + ' (' + selected + ' <?php esc_attr_e('blocks', 'DuweWP_DisableBlocks'); ?>)';
			} else {
				submitButton.value = '<?php esc_attr_e('Save Changes', 'DuweWP_DisableBlocks'); ?>';
			}
		}
		
		// Add confirmation for large selections
		form.addEventListener('submit', function(e) {
			const selected = Array.from(select.selectedOptions).length;
			if (selected > 10) {
				if (!confirm('<?php esc_js_e('You are about to disable', 'DuweWP_DisableBlocks'); ?> ' + selected + ' <?php esc_js_e('blocks. This may affect existing content. Are you sure?', 'DuweWP_DisableBlocks'); ?>')) {
					e.preventDefault();
				}
			}
		});
	});
	</script>
	<?php
}

/**
 * Disable selected blocks in the block editor
 */
function duwewp_disableblocks_disable_selected_blocks() {
	// Only run in admin and for users who can edit
	if (!is_admin() || !current_user_can('edit_posts')) {
		return;
	}

	$disabled_blocks = get_option('duwewp_disableblocks_disabled_blocks', []);
	
	if (!is_array($disabled_blocks) || empty($disabled_blocks)) {
		return;
	}

	// Register and enqueue the block disabling script
	wp_register_script(
		'duwewp-disable-blocks',
		'', // Empty URL since we're adding inline script
		['wp-blocks', 'wp-dom-ready', 'wp-data'],
		DUWEWP_DB_VERSION,
		true
	);

	$inline_script = duwewp_disableblocks_generate_inline_script($disabled_blocks);
	wp_add_inline_script('duwewp-disable-blocks', $inline_script);
	wp_enqueue_script('duwewp-disable-blocks');
}
add_action('enqueue_block_editor_assets', 'duwewp_disableblocks_disable_selected_blocks');

/**
 * Generate inline JavaScript to disable blocks
 */
function duwewp_disableblocks_generate_inline_script($blocks) {
	$sanitized_blocks = array_map('sanitize_text_field', $blocks);
	$js_array = wp_json_encode($sanitized_blocks);

	$script = "
	wp.domReady(function() {
		const disabledBlocks = {$js_array};
		const { select, dispatch } = wp.data;
		
		// Function to unregister blocks
		function unregisterBlocks() {
			disabledBlocks.forEach(function(blockName) {
				try {
					if (wp.blocks.getBlockType(blockName)) {
						wp.blocks.unregisterBlockType(blockName);
						console.log('DuweWP: Disabled block - ' + blockName);
					}
				} catch (error) {
					console.warn('DuweWP: Could not disable block - ' + blockName, error);
				}
			});
		}
		
		// Run immediately
		unregisterBlocks();
		
		// Also run after block editor is fully loaded
		if (select('core/block-editor')) {
			const unsubscribe = select('core/block-editor').subscribe(function() {
				if (select('core/block-editor').getBlocks) {
					unregisterBlocks();
					unsubscribe();
				}
			});
		}
	});
	";

	return $script;
}

/**
 * Add settings link to plugin actions
 */
function duwewp_disableblocks_add_settings_link($links) {
	$settings_link = '<a href="' . admin_url('options-general.php?page=disable-gutenberg-blocks') . '">' . 
					 __('Settings', 'DuweWP_DisableBlocks') . '</a>';
	array_unshift($links, $settings_link);
	return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'duwewp_disableblocks_add_settings_link');

/**
 * Add admin notice if no blocks are disabled (show only once)
 */
function duwewp_disableblocks_admin_notice() {
	$disabled_blocks = get_option('duwewp_disableblocks_disabled_blocks', []);
	$notice_dismissed = get_option('duwewp_disableblocks_notice_dismissed', false);
	
	if (empty($disabled_blocks) && !$notice_dismissed && current_user_can('manage_options')) {
		?>
		<div class="notice notice-info is-dismissible" data-dismissible="duwewp-disable-blocks-notice">
			<p>
				<strong><?php esc_html_e('DuweWP Disable Blocks:', 'DuweWP_DisableBlocks'); ?></strong>
				<?php esc_html_e('No blocks are currently disabled.', 'DuweWP_DisableBlocks'); ?>
				<a href="<?php echo esc_url(admin_url('options-general.php?page=disable-gutenberg-blocks')); ?>">
					<?php esc_html_e('Configure now', 'DuweWP_DisableBlocks'); ?>
				</a>
			</p>
		</div>
		<script>
		jQuery(document).on('click', '.notice[data-dismissible="duwewp-disable-blocks-notice"] .notice-dismiss', function() {
			jQuery.post(ajaxurl, {
				action: 'duwewp_dismiss_notice',
				nonce: '<?php echo wp_create_nonce('duwewp_dismiss_notice'); ?>'
			});
		});
		</script>
		<?php
	}
}
add_action('admin_notices', 'duwewp_disableblocks_admin_notice');

/**
 * Handle notice dismissal
 */
function duwewp_disableblocks_dismiss_notice() {
	if (wp_verify_nonce($_POST['nonce'], 'duwewp_dismiss_notice')) {
		update_option('duwewp_disableblocks_notice_dismissed', true);
	}
	wp_die();
}
add_action('wp_ajax_duwewp_dismiss_notice', 'duwewp_disableblocks_dismiss_notice');