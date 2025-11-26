<?php
/**
 * Plugin Name: Image Size Manager
 * Plugin URI: https://dcarock.com/wordpress/
 * Description: Manage WordPress image sizes with enable/disable toggles and regenerate thumbnails.
 * Version: 3.0.0
 * Author: Chris Arock
 * Author URI: https://dcarock.com
 * Text Domain: image-size-manager
 * License: GPL-2.0+
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('ISM_VERSION', '3.0.0');
define('ISM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ISM_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
class Image_Size_Manager {
    
    /**
     * Instance of this class
     */
    private static $instance = null;
    
    /**
     * Settings option name
     */
    private $option_name = 'ism_settings';

    /**
     * Get a singleton instance of this class
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize the plugin
     */
    private function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Filter image sizes
        add_filter('intermediate_image_sizes_advanced', array($this, 'filter_image_sizes'), 10, 1);
        
        // Ajax handler for regenerating thumbnails
        add_action('wp_ajax_ism_regenerate_thumbnails', array($this, 'ajax_regenerate_thumbnails'));

        // Ajax handler for regenerating individual size
        add_action('wp_ajax_ism_regenerate_size', array($this, 'ajax_regenerate_size'));

        // Ajax handler for storing pre-regeneration memory
        add_action('wp_ajax_ism_store_memory', array($this, 'ajax_store_memory'));

        // Ajax handler for clearing savings data
        add_action('wp_ajax_ism_clear_savings', array($this, 'ajax_clear_savings'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_media_page(
            __('Image Size Manager', 'image-size-manager'),
            __('Image Sizes', 'image-size-manager'),
            'manage_options',
            'image-size-manager',
            array($this, 'display_admin_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'ism_settings_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized_input = array();
        
        // Get all registered image sizes
        $image_sizes = $this->get_all_image_sizes();
        
        foreach ($image_sizes as $size_name => $size_data) {
            $sanitized_input[$size_name] = isset($input[$size_name]) ? true : false;
        }
        
        return $sanitized_input;
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ('media_page_image-size-manager' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'image-size-manager-css',
            ISM_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            ISM_VERSION
        );
        
        wp_enqueue_script(
            'image-size-manager-js',
            ISM_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'jquery-ui-progressbar'),
            ISM_VERSION,
            true
        );
        
        wp_localize_script(
            'image-size-manager-js',
            'ism_data',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ism_nonce'),
                'regenerate_start' => __('Starting regeneration...', 'image-size-manager'),
                'regenerate_processing' => __('Processing image %1$s of %2$s (%3$s%%)...', 'image-size-manager'),
                'regenerate_complete' => __('Regeneration complete!', 'image-size-manager'),
                'regenerate_error' => __('Error: ', 'image-size-manager')
            )
        );
    }
    
    /**
     * Display admin page
     */
    public function display_admin_page() {
        // Get all registered image sizes
        $image_sizes = $this->get_all_image_sizes();
        
        // Get saved settings
        $settings = get_option($this->option_name, array());
        
        // Default all to enabled if no settings saved yet
        if (empty($settings)) {
            foreach ($image_sizes as $size_name => $size_data) {
                $settings[$size_name] = true;
            }
        }

        // Calculate total memory usage
        $total_memory = $this->calculate_total_memory_usage();

        // Get memory savings if available
        $memory_savings = $this->get_memory_savings();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Image Size Manager', 'image-size-manager'); ?></h1>

            <div class="ism-memory-stats">
                <div class="ism-total-memory">
                    <h2><?php echo esc_html__('Total Image Size Memory Usage', 'image-size-manager'); ?></h2>
                    <p class="ism-memory-value"><?php echo esc_html($total_memory['formatted']); ?></p>
                    <p class="ism-memory-description"><?php echo esc_html__('Total disk space used by all generated image sizes', 'image-size-manager'); ?></p>
                </div>

                <?php if ($memory_savings !== null) : ?>
                <div class="ism-memory-savings <?php echo $memory_savings['is_savings'] ? 'ism-savings-positive' : 'ism-savings-negative'; ?>">
                    <h3>
                        <?php if ($memory_savings['is_savings']) : ?>
                            <?php echo esc_html__('Memory Saved After Last Regeneration', 'image-size-manager'); ?>
                        <?php else : ?>
                            <?php echo esc_html__('Memory Added After Last Regeneration', 'image-size-manager'); ?>
                        <?php endif; ?>
                    </h3>
                    <p class="ism-savings-value">
                        <?php if ($memory_savings['is_savings']) : ?>
                            <span class="ism-savings-icon">✓</span> <?php echo esc_html($memory_savings['saved_formatted']); ?>
                            <span class="ism-savings-percentage">(<?php echo esc_html(number_format($memory_savings['percentage'], 1)); ?>% reduction)</span>
                        <?php else : ?>
                            <span class="ism-savings-icon">+</span> <?php echo esc_html($memory_savings['saved_formatted']); ?>
                            <span class="ism-savings-percentage">(<?php echo esc_html(number_format(abs($memory_savings['percentage']), 1)); ?>% increase)</span>
                        <?php endif; ?>
                    </p>
                    <button type="button" id="ism-clear-savings" class="button button-small">
                        <?php echo esc_html__('Clear Savings Data', 'image-size-manager'); ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('ism_settings_group'); ?>
                
                <button type="button" id="ism-regenerate-button" class="button button-secondary">
                    <?php echo esc_html__('Regenerate Thumbnails', 'image-size-manager'); ?>
                </button>
                
                <div id="ism-regenerate-progress-container" style="display: none; margin-top: 15px; margin-bottom: 15px;">
                    <div id="ism-regenerate-progress"></div>
                    <div id="ism-regenerate-status"></div>
                </div>
                
                <table class="widefat ism-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Image Size', 'image-size-manager'); ?></th>
                            <th><?php echo esc_html__('Width', 'image-size-manager'); ?></th>
                            <th><?php echo esc_html__('Height', 'image-size-manager'); ?></th>
                            <th><?php echo esc_html__('Crop', 'image-size-manager'); ?></th>
                            <th><?php echo esc_html__('Total Count', 'image-size-manager'); ?></th>
                            <th><?php echo esc_html__('Total Size', 'image-size-manager'); ?></th>
                            <th><?php echo esc_html__('Status', 'image-size-manager'); ?></th>
                            <th><?php echo esc_html__('Actions', 'image-size-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($image_sizes as $size_name => $size_data) :
                            $usage_count = $this->count_images_with_size($size_name);
                            $total_size = $this->calculate_total_size($size_name);
                            $is_enabled = isset($settings[$size_name]) && $settings[$size_name];
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html($size_name); ?></strong></td>
                                <td><?php echo isset($size_data['width']) ? esc_html($size_data['width']) : 'N/A'; ?></td>
                                <td><?php echo isset($size_data['height']) ? esc_html($size_data['height']) : 'N/A'; ?></td>
                                <td><?php echo isset($size_data['crop']) ? ($size_data['crop'] ? esc_html__('Yes', 'image-size-manager') : esc_html__('No', 'image-size-manager')) : 'N/A'; ?></td>
                                <td><?php echo esc_html($usage_count); ?></td>
                                <td><?php echo esc_html($total_size); ?></td>
                                <td>
                                    <label class="ism-switch">
                                        <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[<?php echo esc_attr($size_name); ?>]" value="1" <?php checked($is_enabled); ?>>
                                        <span class="ism-slider"></span>
                                    </label>
                                </td>
                                <td>
                                    <button type="button" class="button button-small ism-regenerate-size" data-size="<?php echo esc_attr($size_name); ?>" <?php echo !$is_enabled ? 'disabled title="' . esc_attr__('Enable this size to regenerate', 'image-size-manager') . '"' : ''; ?>>
                                        <?php echo esc_html__('Regenerate', 'image-size-manager'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <p class="submit">
                    <?php submit_button(null, 'primary', 'submit', false); ?>
                </p>
            </form>
            
            <div id="ism-refresh-container" style="display: none; margin-top: 20px;">
                <button type="button" id="ism-refresh-button" class="button button-primary" onclick="window.location.reload();">
                    <?php echo esc_html__('Refresh Page', 'image-size-manager'); ?>
                </button>
                <p><?php echo esc_html__('Thumbnails have been regenerated. Refresh the page to see updated statistics.', 'image-size-manager'); ?></p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get all registered image sizes
     */
    private function get_all_image_sizes() {
        $image_sizes = array();
        
        // Get default WordPress image sizes
        $default_sizes = array('thumbnail', 'medium', 'medium_large', 'large');
        
        foreach ($default_sizes as $size) {
            $image_sizes[$size] = array(
                'width' => intval(get_option($size . '_size_w')),
                'height' => intval(get_option($size . '_size_h')),
                'crop' => (bool) get_option($size . '_crop')
            );
        }
        
        // Get custom image sizes
        global $_wp_additional_image_sizes;
        
        if (isset($_wp_additional_image_sizes) && count($_wp_additional_image_sizes)) {
            $image_sizes = array_merge($image_sizes, $_wp_additional_image_sizes);
        }
        
        return $image_sizes;
    }
    
    /**
     * Count how many images in the library have a specific image size
     *
     * @param string $size_name The name of the image size
     * @return string The number of images with this size (with indicator if limited)
     */
    private function count_images_with_size($size_name) {
        // Get total count first
        $total_query = new WP_Query(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
        ));

        $total_images = $total_query->found_posts;

        // Limit to 1000 to avoid timeouts on large sites
        $limit = min(1000, $total_images);

        // Query for image attachments
        $query = new WP_Query(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => $limit,
            'fields' => 'ids',
        ));

        $count = 0;

        // Loop through each attachment and check if it has the specified size
        if (!empty($query->posts)) {
            foreach ($query->posts as $attachment_id) {
                $metadata = wp_get_attachment_metadata($attachment_id);

                // If the size exists in the metadata, increment the count
                if (is_array($metadata) && isset($metadata['sizes']) && isset($metadata['sizes'][$size_name])) {
                    $count++;
                }
            }
        }

        // Add indicator if we're only showing partial results
        if ($total_images > 1000) {
            return $count . ' (of ' . $limit . ' checked)';
        }

        return (string) $count;
    }
    
    /**
     * Calculate the total disk space used by a specific image size
     *
     * @param string $size_name The name of the image size
     * @return string Formatted file size in MB (with indicator if limited)
     */
    private function calculate_total_size($size_name) {
        // Get total count first
        $total_query = new WP_Query(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
        ));

        $total_images = $total_query->found_posts;

        // Limit to 1000 to avoid timeouts on large sites
        $limit = min(1000, $total_images);

        // Query for image attachments
        $query = new WP_Query(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => $limit,
            'fields' => 'ids',
        ));

        $total_size = 0;
        $upload_dir = wp_upload_dir();

        // Validate upload directory
        if (empty($upload_dir['basedir'])) {
            return '0.00 MB';
        }

        $base_dir = $upload_dir['basedir'];

        // Loop through each attachment and calculate the size
        if (!empty($query->posts)) {
            foreach ($query->posts as $attachment_id) {
                $metadata = wp_get_attachment_metadata($attachment_id);

                // Make sure all required data exists
                if (empty($metadata) || !is_array($metadata) ||
                    !isset($metadata['file']) || !isset($metadata['sizes']) ||
                    !isset($metadata['sizes'][$size_name]) ||
                    !isset($metadata['sizes'][$size_name]['file'])) {
                    continue;
                }

                try {
                    // Get the file path
                    $file_path = pathinfo($metadata['file'], PATHINFO_DIRNAME);
                    $size_file = $metadata['sizes'][$size_name]['file'];

                    // Ensure we have valid data
                    if (empty($size_file)) {
                        continue;
                    }

                    // Build full path
                    if (!empty($file_path) && $file_path !== '.') {
                        $full_path = $base_dir . '/' . $file_path . '/' . $size_file;
                    } else {
                        $full_path = $base_dir . '/' . $size_file;
                    }

                    // Add the file size if it exists
                    if (file_exists($full_path) && is_readable($full_path)) {
                        $file_size = @filesize($full_path);
                        if ($file_size !== false) {
                            $total_size += $file_size;
                        }
                    }
                } catch (Exception $e) {
                    // Silently fail for any individual image
                    continue;
                }
            }
        }

        // Convert to MB and format
        $total_size_mb = $total_size / (1024 * 1024);
        $formatted_size = number_format($total_size_mb, 2) . ' MB';

        // Add indicator if we're only showing partial results
        if ($total_images > 1000) {
            $formatted_size .= ' (est.)';
        }

        return $formatted_size;
    }

    /**
     * Calculate the total memory usage across all image sizes
     *
     * @return array Array with 'bytes' and 'formatted' keys
     */
    private function calculate_total_memory_usage() {
        $image_sizes = $this->get_all_image_sizes();
        $total_bytes = 0;

        // Get total count first
        $total_query = new WP_Query(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
        ));

        $total_images = $total_query->found_posts;

        // Limit to 1000 to avoid timeouts on large sites
        $limit = min(1000, $total_images);

        // Query for image attachments
        $query = new WP_Query(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => $limit,
            'fields' => 'ids',
        ));

        $upload_dir = wp_upload_dir();

        // Validate upload directory
        if (empty($upload_dir['basedir'])) {
            return array('bytes' => 0, 'formatted' => '0.00 MB');
        }

        $base_dir = $upload_dir['basedir'];

        // Loop through each attachment
        if (!empty($query->posts)) {
            foreach ($query->posts as $attachment_id) {
                $metadata = wp_get_attachment_metadata($attachment_id);

                // Make sure all required data exists
                if (empty($metadata) || !is_array($metadata) ||
                    !isset($metadata['file']) || !isset($metadata['sizes']) ||
                    !is_array($metadata['sizes'])) {
                    continue;
                }

                // Loop through all sizes for this attachment
                foreach ($metadata['sizes'] as $size_name => $size_data) {
                    if (!isset($size_data['file'])) {
                        continue;
                    }

                    try {
                        // Get the file path
                        $file_path = pathinfo($metadata['file'], PATHINFO_DIRNAME);
                        $size_file = $size_data['file'];

                        // Ensure we have valid data
                        if (empty($size_file)) {
                            continue;
                        }

                        // Build full path
                        if (!empty($file_path) && $file_path !== '.') {
                            $full_path = $base_dir . '/' . $file_path . '/' . $size_file;
                        } else {
                            $full_path = $base_dir . '/' . $size_file;
                        }

                        // Add the file size if it exists
                        if (file_exists($full_path) && is_readable($full_path)) {
                            $file_size = @filesize($full_path);
                            if ($file_size !== false) {
                                $total_bytes += $file_size;
                            }
                        }
                    } catch (Exception $e) {
                        // Silently fail for any individual image
                        continue;
                    }
                }
            }
        }

        // Convert to MB and format
        $total_size_mb = $total_bytes / (1024 * 1024);
        $formatted_size = number_format($total_size_mb, 2) . ' MB';

        // Add indicator if we're only showing partial results
        if ($total_images > 1000) {
            $formatted_size .= ' (estimated)';
        }

        return array(
            'bytes' => $total_bytes,
            'formatted' => $formatted_size
        );
    }

    /**
     * Store memory usage before regeneration
     */
    private function store_pre_regeneration_memory() {
        $memory_data = $this->calculate_total_memory_usage();
        update_option('ism_pre_regeneration_memory', $memory_data['bytes']);
        update_option('ism_pre_regeneration_time', current_time('timestamp'));
    }

    /**
     * Get memory savings after regeneration
     *
     * @return array|null Array with 'saved_bytes', 'saved_formatted', and 'percentage' keys, or null if no data
     */
    private function get_memory_savings() {
        $pre_memory = get_option('ism_pre_regeneration_memory', null);

        if ($pre_memory === null) {
            return null;
        }

        $current_memory = $this->calculate_total_memory_usage();
        $saved_bytes = $pre_memory - $current_memory['bytes'];
        $saved_mb = $saved_bytes / (1024 * 1024);

        // Calculate percentage
        $percentage = 0;
        if ($pre_memory > 0) {
            $percentage = ($saved_bytes / $pre_memory) * 100;
        }

        return array(
            'saved_bytes' => $saved_bytes,
            'saved_formatted' => number_format(abs($saved_mb), 2) . ' MB',
            'percentage' => $percentage,
            'is_savings' => $saved_bytes > 0
        );
    }

    /**
     * Clear memory savings data
     */
    private function clear_memory_savings() {
        delete_option('ism_pre_regeneration_memory');
        delete_option('ism_pre_regeneration_time');
    }

    /**
     * Filter image sizes based on settings
     */
    public function filter_image_sizes($sizes) {
        $settings = get_option($this->option_name, array());

        // If no settings saved yet, allow all sizes (first time use)
        if (empty($settings)) {
            return $sizes;
        }

        // Remove disabled sizes
        foreach ($sizes as $size_name => $size_data) {
            // If setting exists and is false (disabled), remove the size
            // If setting doesn't exist (new size added by theme/plugin), keep it enabled by default
            if (isset($settings[$size_name]) && $settings[$size_name] === false) {
                unset($sizes[$size_name]);
            }
        }

        return $sizes;
    }

    /**
     * Get enabled image sizes based on settings
     *
     * @return array Array of enabled size names
     */
    private function get_enabled_sizes() {
        $settings = get_option($this->option_name, array());
        $all_sizes = $this->get_all_image_sizes();
        $enabled_sizes = array();

        foreach ($all_sizes as $size_name => $size_data) {
            // If no settings exist, default to enabled
            // If setting exists and is true, it's enabled
            // If setting exists and is false, it's disabled
            if (!isset($settings[$size_name]) || $settings[$size_name] === true) {
                $enabled_sizes[] = $size_name;
            }
        }

        return $enabled_sizes;
    }
    
    /**
     * Ajax handler for regenerating thumbnails
     */
    public function ajax_regenerate_thumbnails() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ism_nonce')) {
            wp_send_json_error(__('Security check failed', 'image-size-manager'));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to do this', 'image-size-manager'));
            return;
        }

        // Get attachment ID
        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;

        // Get total attachments for first request
        if ($attachment_id === 0) {
            $query = new WP_Query(array(
                'post_type' => 'attachment',
                'post_mime_type' => 'image',
                'post_status' => 'inherit',
                'fields' => 'ids',
                'posts_per_page' => -1,
            ));

            wp_send_json_success(array(
                'total' => $query->post_count,
                'ids' => $query->posts
            ));
            return;
        }

        // Regenerate thumbnails for a specific attachment
        if ($attachment_id > 0) {
            $result = $this->regenerate_thumbnails($attachment_id);

            if (is_wp_error($result)) {
                // Return error with attachment ID for better tracking
                wp_send_json_error(array(
                    'message' => $result->get_error_message(),
                    'attachment_id' => $attachment_id,
                    'error_code' => $result->get_error_code()
                ));
            } else {
                wp_send_json_success(array(
                    'attachment_id' => $attachment_id
                ));
            }
            return;
        }

        wp_send_json_error(__('Invalid request', 'image-size-manager'));
    }

    /**
     * Ajax handler for regenerating a specific image size
     */
    public function ajax_regenerate_size() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ism_nonce')) {
            wp_send_json_error(__('Security check failed', 'image-size-manager'));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to do this', 'image-size-manager'));
            return;
        }

        // Get size name
        $size_name = isset($_POST['size_name']) ? sanitize_text_field(wp_unslash($_POST['size_name'])) : '';

        if (empty($size_name)) {
            wp_send_json_error(__('Invalid size name', 'image-size-manager'));
            return;
        }

        // Get attachment ID
        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;

        // Get total attachments for first request
        if ($attachment_id === 0) {
            $query = new WP_Query(array(
                'post_type' => 'attachment',
                'post_mime_type' => 'image',
                'post_status' => 'inherit',
                'fields' => 'ids',
                'posts_per_page' => -1,
            ));

            wp_send_json_success(array(
                'total' => $query->post_count,
                'ids' => $query->posts
            ));
            return;
        }

        // Regenerate specific size for a specific attachment
        if ($attachment_id > 0) {
            $result = $this->regenerate_single_size($attachment_id, $size_name);

            if (is_wp_error($result)) {
                // Return error with attachment ID and size for better tracking
                wp_send_json_error(array(
                    'message' => $result->get_error_message(),
                    'attachment_id' => $attachment_id,
                    'size_name' => $size_name,
                    'error_code' => $result->get_error_code()
                ));
            } else {
                wp_send_json_success(array(
                    'attachment_id' => $attachment_id,
                    'size_name' => $size_name
                ));
            }
            return;
        }

        wp_send_json_error(__('Invalid request', 'image-size-manager'));
    }

    /**
     * Ajax handler for storing pre-regeneration memory
     */
    public function ajax_store_memory() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ism_nonce')) {
            wp_send_json_error(__('Security check failed', 'image-size-manager'));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to do this', 'image-size-manager'));
            return;
        }

        $this->store_pre_regeneration_memory();
        wp_send_json_success();
    }

    /**
     * Ajax handler for clearing savings data
     */
    public function ajax_clear_savings() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ism_nonce')) {
            wp_send_json_error(__('Security check failed', 'image-size-manager'));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to do this', 'image-size-manager'));
            return;
        }

        $this->clear_memory_savings();
        wp_send_json_success();
    }

    /**
     * Regenerate thumbnails for a specific attachment
     *
     * @param int $attachment_id The attachment ID
     * @return true|WP_Error True on success, WP_Error on failure
     */
    private function regenerate_thumbnails($attachment_id) {
        // Validate attachment ID
        if (empty($attachment_id) || !is_numeric($attachment_id)) {
            return new WP_Error('invalid_id', sprintf(__('Invalid attachment ID: %s', 'image-size-manager'), $attachment_id));
        }

        $attachment = get_post($attachment_id);

        // Validate attachment exists and is an image
        if (!$attachment || 'attachment' !== $attachment->post_type) {
            return new WP_Error('not_attachment', sprintf(__('Attachment ID %d is not a valid attachment', 'image-size-manager'), $attachment_id));
        }

        if (!$attachment->post_mime_type || 'image/' !== substr($attachment->post_mime_type, 0, 6)) {
            return new WP_Error('not_image', sprintf(__('Attachment ID %d is not an image (mime type: %s)', 'image-size-manager'), $attachment_id, $attachment->post_mime_type));
        }

        // Get the original image file path with explicit validation
        $file_path = get_attached_file($attachment_id);

        if (false === $file_path || empty($file_path)) {
            return new WP_Error('no_file_path', sprintf(__('Could not retrieve file path for attachment ID %d', 'image-size-manager'), $attachment_id));
        }

        // Validate file exists
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', sprintf(__('File not found for attachment ID %d (path: %s)', 'image-size-manager'), $attachment_id, $file_path));
        }

        // Validate file is readable
        if (!is_readable($file_path)) {
            return new WP_Error('file_not_readable', sprintf(__('File not readable for attachment ID %d (path: %s)', 'image-size-manager'), $attachment_id, $file_path));
        }

        // Validate it's actually a file (not a directory)
        if (!is_file($file_path)) {
            return new WP_Error('not_a_file', sprintf(__('Path is not a file for attachment ID %d (path: %s)', 'image-size-manager'), $attachment_id, $file_path));
        }

        // Check file size
        $file_size = @filesize($file_path);
        if (false === $file_size || $file_size === 0) {
            return new WP_Error('empty_file', sprintf(__('File is empty or unreadable for attachment ID %d (path: %s)', 'image-size-manager'), $attachment_id, $file_path));
        }

        // Include image functions if not already loaded
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }

        // Remove old thumbnails
        $metadata = wp_get_attachment_metadata($attachment_id);
        $this->remove_old_thumbnails($file_path, $metadata);

        // Generate new metadata and thumbnails
        // The intermediate_image_sizes_advanced filter should filter out disabled sizes
        $new_metadata = wp_generate_attachment_metadata($attachment_id, $file_path);

        if (is_wp_error($new_metadata)) {
            return new WP_Error(
                $new_metadata->get_error_code(),
                sprintf(
                    __('Failed to generate metadata for attachment ID %d: %s (path: %s, size: %s)', 'image-size-manager'),
                    $attachment_id,
                    $new_metadata->get_error_message(),
                    $file_path,
                    size_format($file_size)
                )
            );
        }

        // Handle empty metadata
        if (empty($new_metadata)) {
            return new WP_Error('empty_metadata', sprintf(__('Generated metadata is empty for attachment ID %d', 'image-size-manager'), $attachment_id));
        }

        // Double-check: Remove any disabled size files that might have been generated
        // This ensures disabled sizes are truly not present after regeneration
        $new_metadata = $this->filter_metadata_sizes($new_metadata, $file_path);

        // Update attachment metadata
        wp_update_attachment_metadata($attachment_id, $new_metadata);

        return true;
    }

    /**
     * Regenerate a specific image size for an attachment
     *
     * @param int $attachment_id The attachment ID
     * @param string $size_name The name of the size to regenerate
     * @return true|WP_Error True on success, WP_Error on failure
     */
    private function regenerate_single_size($attachment_id, $size_name) {
        // Validate attachment ID
        if (empty($attachment_id) || !is_numeric($attachment_id)) {
            return new WP_Error('invalid_id', sprintf(__('Invalid attachment ID: %s', 'image-size-manager'), $attachment_id));
        }

        $attachment = get_post($attachment_id);

        // Validate attachment exists and is an image
        if (!$attachment || 'attachment' !== $attachment->post_type) {
            return new WP_Error('not_attachment', sprintf(__('Attachment ID %d is not a valid attachment', 'image-size-manager'), $attachment_id));
        }

        if (!$attachment->post_mime_type || 'image/' !== substr($attachment->post_mime_type, 0, 6)) {
            return new WP_Error('not_image', sprintf(__('Attachment ID %d is not an image (mime type: %s)', 'image-size-manager'), $attachment_id, $attachment->post_mime_type));
        }

        // Get the original image file path with explicit validation
        $file_path = get_attached_file($attachment_id);

        if (false === $file_path || empty($file_path)) {
            return new WP_Error('no_file_path', sprintf(__('Could not retrieve file path for attachment ID %d', 'image-size-manager'), $attachment_id));
        }

        // Validate file exists
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', sprintf(__('File not found for attachment ID %d (path: %s)', 'image-size-manager'), $attachment_id, $file_path));
        }

        // Validate file is readable
        if (!is_readable($file_path)) {
            return new WP_Error('file_not_readable', sprintf(__('File not readable for attachment ID %d (path: %s)', 'image-size-manager'), $attachment_id, $file_path));
        }

        // Validate it's actually a file (not a directory)
        if (!is_file($file_path)) {
            return new WP_Error('not_a_file', sprintf(__('Path is not a file for attachment ID %d (path: %s)', 'image-size-manager'), $attachment_id, $file_path));
        }

        // Check file size
        $file_size = @filesize($file_path);
        if (false === $file_size || $file_size === 0) {
            return new WP_Error('empty_file', sprintf(__('File is empty or unreadable for attachment ID %d (path: %s)', 'image-size-manager'), $attachment_id, $file_path));
        }

        // Include image functions if not already loaded
        if (!function_exists('wp_get_image_editor')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }

        // Get all available sizes
        $all_sizes = $this->get_all_image_sizes();

        // Check if the requested size exists
        if (!isset($all_sizes[$size_name])) {
            return new WP_Error('invalid_size', sprintf(__('Invalid image size "%s" for attachment ID %d', 'image-size-manager'), $size_name, $attachment_id));
        }

        // Get current metadata
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (!$metadata || !is_array($metadata)) {
            return new WP_Error('no_metadata', sprintf(__('Could not retrieve metadata for attachment ID %d', 'image-size-manager'), $attachment_id));
        }

        // Get size data
        $size_data = $all_sizes[$size_name];
        $width = isset($size_data['width']) ? intval($size_data['width']) : 0;
        $height = isset($size_data['height']) ? intval($size_data['height']) : 0;
        $crop = isset($size_data['crop']) ? $size_data['crop'] : false;

        // Get original image dimensions
        $orig_width = isset($metadata['width']) ? intval($metadata['width']) : 0;
        $orig_height = isset($metadata['height']) ? intval($metadata['height']) : 0;

        // Check if original image is large enough
        // Skip if image is smaller than requested size (WordPress behavior)
        if ($orig_width > 0 && $orig_height > 0) {
            // If not cropping and image is smaller, skip
            if (!$crop && ($orig_width < $width && $orig_height < $height)) {
                // Remove from metadata if it exists
                if (isset($metadata['sizes'][$size_name])) {
                    // Delete the file if it exists
                    if (isset($metadata['sizes'][$size_name]['file'])) {
                        $old_file = $this->build_path(dirname($file_path), $metadata['sizes'][$size_name]['file']);
                        $this->safe_delete_file($old_file);
                    }
                    unset($metadata['sizes'][$size_name]);
                    wp_update_attachment_metadata($attachment_id, $metadata);
                }
                return true; // Not an error, just skip this image
            }
        }

        // Remove old version of this size if it exists
        if (isset($metadata['sizes'][$size_name]) && isset($metadata['sizes'][$size_name]['file'])) {
            $old_file = $this->build_path(dirname($file_path), $metadata['sizes'][$size_name]['file']);
            $this->safe_delete_file($old_file);
        }

        // Get image editor - THIS is where "File is not an image" errors come from
        $editor = wp_get_image_editor($file_path);

        if (is_wp_error($editor)) {
            // Add more context to the error
            return new WP_Error(
                $editor->get_error_code(),
                sprintf(
                    __('Failed to load image editor for attachment ID %d: %s (path: %s, size: %s)', 'image-size-manager'),
                    $attachment_id,
                    $editor->get_error_message(),
                    $file_path,
                    size_format($file_size)
                )
            );
        }

        // Resize the image
        $resized = $editor->resize($width, $height, $crop);

        if (is_wp_error($resized)) {
            // If resize fails due to image being too small, skip it gracefully
            if (strpos($resized->get_error_message(), 'dimensions') !== false) {
                // Remove from metadata if it exists
                if (isset($metadata['sizes'][$size_name])) {
                    unset($metadata['sizes'][$size_name]);
                    wp_update_attachment_metadata($attachment_id, $metadata);
                }
                return true; // Not a fatal error, just skip
            }
            return new WP_Error(
                $resized->get_error_code(),
                sprintf(__('Resize failed for attachment ID %d, size "%s": %s', 'image-size-manager'), $attachment_id, $size_name, $resized->get_error_message())
            );
        }

        // Generate filename
        $dest_file_name = $editor->generate_filename(
            $size_name,
            dirname($file_path),
            null
        );

        // Save the resized image
        $saved = $editor->save($dest_file_name);

        if (is_wp_error($saved)) {
            return new WP_Error(
                $saved->get_error_code(),
                sprintf(__('Save failed for attachment ID %d, size "%s": %s', 'image-size-manager'), $attachment_id, $size_name, $saved->get_error_message())
            );
        }

        // Update metadata with new size information
        if (!isset($metadata['sizes'])) {
            $metadata['sizes'] = array();
        }

        $metadata['sizes'][$size_name] = array(
            'file' => basename($saved['path']),
            'width' => $saved['width'],
            'height' => $saved['height'],
            'mime-type' => $saved['mime-type']
        );

        // Update attachment metadata
        wp_update_attachment_metadata($attachment_id, $metadata);

        return true;
    }

    /**
     * Safely build a file path from directory and filename
     *
     * @param string $dir Directory path
     * @param string $file Filename
     * @return string Complete file path
     */
    private function build_path($dir, $file) {
        // Normalize directory separators
        $dir = rtrim($dir, '/\\');
        $file = ltrim($file, '/\\');

        return $dir . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * Safely delete a file with proper error checking
     *
     * @param string $file_path Path to file to delete
     * @return bool True on success or if file doesn't exist, false on failure
     */
    private function safe_delete_file($file_path) {
        // Don't attempt to delete if path is empty
        if (empty($file_path)) {
            return true;
        }

        // Check if file exists
        if (!file_exists($file_path)) {
            return true; // Already gone, consider it success
        }

        // Ensure it's actually a file (not a directory)
        if (!is_file($file_path)) {
            return false;
        }

        // Attempt to delete the file
        $result = @unlink($file_path);

        // Log error if deletion failed (but don't crash)
        if (!$result) {
            error_log(sprintf('Image Size Manager: Failed to delete file: %s', $file_path));
        }

        return $result;
    }

    /**
     * Filter metadata to remove disabled sizes and delete their files
     *
     * @param array $metadata Attachment metadata
     * @param string $file_path Path to the original file
     * @return array Filtered metadata
     */
    private function filter_metadata_sizes($metadata, $file_path) {
        if (empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            return $metadata;
        }

        $enabled_sizes = $this->get_enabled_sizes();
        $dir_path = dirname($file_path);

        // Remove disabled sizes from metadata and delete their files
        foreach ($metadata['sizes'] as $size_name => $size_data) {
            if (!in_array($size_name, $enabled_sizes, true)) {
                // Delete the file if it exists
                if (isset($size_data['file'])) {
                    $file_to_delete = $this->build_path($dir_path, $size_data['file']);
                    $this->safe_delete_file($file_to_delete);
                }

                // Remove from metadata
                unset($metadata['sizes'][$size_name]);
            }
        }

        return $metadata;
    }
    
    /**
     * Remove old thumbnails
     *
     * @param string $file_path Path to the original file
     * @param array|false $metadata Attachment metadata
     */
    private function remove_old_thumbnails($file_path, $metadata) {
        // Validate inputs
        if (empty($file_path) || !is_string($file_path)) {
            return;
        }

        if (empty($metadata) || !is_array($metadata) || empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            return;
        }

        $dir_path = dirname($file_path);

        // Validate directory path
        if (empty($dir_path) || !is_dir($dir_path)) {
            return;
        }

        // Remove each thumbnail file
        foreach ($metadata['sizes'] as $size => $sizeinfo) {
            if (!isset($sizeinfo['file']) || empty($sizeinfo['file'])) {
                continue;
            }

            $file = $this->build_path($dir_path, $sizeinfo['file']);
            $this->safe_delete_file($file);
        }
    }
}

// Initialize the plugin
function image_size_manager_init() {
    Image_Size_Manager::get_instance();
}
add_action('plugins_loaded', 'image_size_manager_init');