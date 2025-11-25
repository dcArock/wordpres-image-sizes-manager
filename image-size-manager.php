<?php
/**
 * Plugin Name: Image Size Manager
 * Plugin URI: https://dcarock.com/wordpress/
 * Description: Manage WordPress image sizes with enable/disable toggles and regenerate thumbnails.
 * Version: 1.0.0
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
define('ISM_VERSION', '1.0.0');
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
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Image Size Manager', 'image-size-manager'); ?></h1>
            
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($image_sizes as $size_name => $size_data) : 
                            $usage_count = $this->count_images_with_size($size_name);
                            $total_size = $this->calculate_total_size($size_name);
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
                                        <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[<?php echo esc_attr($size_name); ?>]" value="1" <?php checked(isset($settings[$size_name]) && $settings[$size_name]); ?>>
                                        <span class="ism-slider"></span>
                                    </label>
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
     * @return int The number of images with this size
     */
    private function count_images_with_size($size_name) {
        // Query for image attachments - limit to 1000 to avoid timeouts on large sites
        $query = new WP_Query(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => 1000,
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
        
        return $count;
    }
    
    /**
     * Calculate the total disk space used by a specific image size
     * 
     * @param string $size_name The name of the image size
     * @return string Formatted file size in MB
     */
    private function calculate_total_size($size_name) {
        // Query for image attachments - limit to 1000 to avoid timeouts on large sites
        $query = new WP_Query(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => 1000,
            'fields' => 'ids',
        ));
        
        $total_size = 0;
        $upload_dir = wp_upload_dir();
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
                    if (empty($file_path) || empty($size_file)) {
                        continue;
                    }
                    
                    $full_path = $base_dir . '/' . $file_path . '/' . $size_file;
                    
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
        return number_format($total_size_mb, 2) . ' MB';
    }
    
    /**
     * Filter image sizes based on settings
     */
    public function filter_image_sizes($sizes) {
        $settings = get_option($this->option_name, array());
        
        if (empty($settings)) {
            return $sizes;
        }
        
        foreach ($sizes as $size_name => $size_data) {
            if (isset($settings[$size_name]) && !$settings[$size_name]) {
                unset($sizes[$size_name]);
            }
        }
        
        return $sizes;
    }
    
    /**
     * Ajax handler for regenerating thumbnails
     */
    public function ajax_regenerate_thumbnails() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ism_nonce')) {
            wp_send_json_error(__('Security check failed', 'image-size-manager'));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to do this', 'image-size-manager'));
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
        }
        
        // Regenerate thumbnails for a specific attachment
        if ($attachment_id > 0) {
            $result = $this->regenerate_thumbnails($attachment_id);
            
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            } else {
                wp_send_json_success();
            }
        }
        
        wp_send_json_error(__('Invalid request', 'image-size-manager'));
    }
    
    /**
     * Regenerate thumbnails for a specific attachment
     */
    private function regenerate_thumbnails($attachment_id) {
        $attachment = get_post($attachment_id);
        
        if (!$attachment || 'attachment' !== $attachment->post_type || 'image/' !== substr($attachment->post_mime_type, 0, 6)) {
            return new WP_Error('not_image', __('This is not a valid image attachment', 'image-size-manager'));
        }
        
        // Get the original image file path
        $file_path = get_attached_file($attachment_id);
        
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', __('Original image file not found', 'image-size-manager'));
        }
        
        // Include image functions if not already loaded
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        // Remove old thumbnails
        $metadata = wp_get_attachment_metadata($attachment_id);
        $this->remove_old_thumbnails($file_path, $metadata);
        
        // Generate new metadata and thumbnails
        $new_metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
        
        if (is_wp_error($new_metadata)) {
            return $new_metadata;
        }
        
        // Update attachment metadata
        wp_update_attachment_metadata($attachment_id, $new_metadata);
        
        return true;
    }
    
    /**
     * Remove old thumbnails
     */
    private function remove_old_thumbnails($file_path, $metadata) {
        if (empty($metadata['sizes'])) {
            return;
        }
        
        $dir_path = dirname($file_path) . '/';
        
        foreach ($metadata['sizes'] as $size => $sizeinfo) {
            $file = $dir_path . $sizeinfo['file'];
            
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }
}

// Initialize the plugin
function image_size_manager_init() {
    Image_Size_Manager::get_instance();
}
add_action('plugins_loaded', 'image_size_manager_init');