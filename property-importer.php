<?php
/*
Plugin Name: Property Importer
Description: JSON API'den mülk içe aktarma, zamanlama ve raporlama özellikleri
Version: 1.0
Author: Yunus Güngör
Author URI: https://yunusgungor.com
*/

if (!defined('ABSPATH')) {
    exit;
}

class PropertyImporter {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init() {
        $this->define_constants();
        $this->load_dependencies();
        $this->register_hooks();
    }

    private function define_constants() {
        define('PROPERTY_IMPORTER_VERSION', '1.0.0');
        define('PROPERTY_IMPORTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('PROPERTY_IMPORTER_PLUGIN_URL', plugin_dir_url(__FILE__));
    }

    private function load_dependencies() {
        require_once PROPERTY_IMPORTER_PLUGIN_DIR . 'includes/class-property-importer-api.php';
    }

    private function register_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_start_import', [$this, 'start_import']);
        add_action('wp_ajax_stop_import', [$this, 'stop_import']);
        add_action('wp_ajax_get_import_progress', [$this, 'get_import_progress']);
        add_action('wp_ajax_clear_logs', [$this, 'clear_logs']);
        add_action('wp_ajax_toggle_cron', [$this, 'toggle_cron']);
        add_action('wp_ajax_get_import_stats', [$this, 'get_import_stats']);
        add_action('property_importer_cron', [$this, 'run_import']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_init', [$this, 'register_webhook_settings']);
        add_action('property_importer_sync_cron', [$this, 'sync_properties']);
        register_activation_hook(__FILE__, [$this, 'activate_cron']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate_cron']);
    }

    public function add_admin_menu() {
        add_menu_page(
            'Property Importer',
            'Property Importer',
            'manage_options',
            'property-importer',
            [$this, 'display_admin_page'],
            'dashicons-upload',
            6
        );
    }

    public function display_admin_page() {
        include PROPERTY_IMPORTER_PLUGIN_DIR . 'admin/admin-page.php';
    }

    public function register_settings() {
        register_setting('property_importer_settings', 'property_importer_api_url');
        add_settings_section('property_importer_main', 'API Ayarları', null, 'property_importer_settings');
        add_settings_field('property_importer_api_url', 'API URL', [$this, 'api_url_callback'], 'property_importer_settings', 'property_importer_main');
    }

    public function api_url_callback() {
        $api_url = get_option('property_importer_api_url', '');
        echo '<input type="text" name="property_importer_api_url" value="' . esc_attr($api_url) . '" class="regular-text">';
    }

    public function start_import() {
        $this->check_user_capabilities();
        check_ajax_referer('property_importer_nonce', 'nonce');
        update_option('property_importer_is_running', true);
        wp_schedule_single_event(time(), 'property_importer_cron');
        wp_send_json_success('İçe aktarma başlatıldı.');
    }

    public function stop_import() {
        $this->check_user_capabilities();
        check_ajax_referer('property_importer_nonce', 'nonce');
        update_option('property_importer_is_running', false);
        wp_clear_scheduled_hook('property_importer_cron');
        delete_transient('property_importer_current_batch');
        $this->log('info', 'İçe aktarma işlemi kullanıcı tarafından durduruldu.');
        
        // Durdurma işlemi tamamlanana kadar kısa bir bekleme süresi ekleyin
        sleep(2);
        
        $stats = $this->get_import_stats();
        wp_send_json_success([
            'message' => 'İçe aktarma durduruldu.',
            'logs' => $this->get_logs(),
            'stats' => $stats,
            'is_running' => false,
            'progress' => get_option('property_importer_progress', 0)
        ]);
    }

    public function get_import_progress() {
        check_ajax_referer('property_importer_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Yetkisiz erişim.');
        }
        $progress = get_option('property_importer_progress', 0);
        $logs = $this->get_logs();
        $is_running = get_option('property_importer_is_running', false);
        $stats = [
            'total' => get_option('property_importer_total_properties', 0),
            'imported' => get_option('property_importer_imported_properties', 0),
            'queued' => get_option('property_importer_total_properties', 0) - get_option('property_importer_imported_properties', 0)
        ];
        wp_send_json_success([
            'progress' => $progress,
            'logs' => $logs,
            'is_running' => $is_running,
            'stats' => $stats
        ]);
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook != 'toplevel_page_property-importer') {
            return;
        }
        wp_enqueue_script('property-importer-admin', PROPERTY_IMPORTER_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], PROPERTY_IMPORTER_VERSION, true);
        wp_localize_script('property-importer-admin', 'property_importer_ajax', [
            'nonce' => wp_create_nonce('property_importer_nonce'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'plugin_url' => PROPERTY_IMPORTER_PLUGIN_URL
        ]);
    }

    public function schedule_import() {
        if (!wp_next_scheduled('property_importer_cron')) {
            wp_schedule_event(time(), 'daily', 'property_importer_cron');
        }
    }

    public function unschedule_import() {
        $timestamp = wp_next_scheduled('property_importer_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'property_importer_cron');
        }
    }

    public function run_import() {
        if (!get_option('property_importer_is_running', false)) {
            $this->log('info', 'İçe aktarma işlemi durduruldu.');
            return;
        }

        $this->log('info', 'İçe aktarma işlemi başlatıldı.');

        $api = new Property_Importer_API();
        $properties = $api->fetch_properties();

        if (is_wp_error($properties)) {
            $this->log('error', 'API\'den veri alınamadı: ' . $properties->get_error_message());
            update_option('property_importer_is_running', false);
            return;
        }

        $total = count($properties);
        $imported = 0;
        $errors = 0;

        foreach ($properties as $property) {
            if (!get_option('property_importer_is_running', false)) {
                $this->log('info', 'İçe aktarma işlemi kullanıcı tarafından durduruldu.');
                break;
            }

            try {
                if ($this->import_property($property)) {
                    $imported++;
                    $this->log('success', 'Mülk başarıyla içe aktarıldı.', ['title' => $property['title']]);
                } else {
                    $errors++;
                    $this->log('error', 'Mülk içe aktarılamadı.', ['title' => $property['title']]);
                }
            } catch (Exception $e) {
                $errors++;
                $this->log('error', 'Mülk içe aktarılırken hata oluştu: ' . $e->getMessage(), ['title' => $property['title']]);
            }

            $progress = ($imported + $errors) / $total * 100;
            update_option('property_importer_progress', $progress);

            $this->update_import_stats($total, $imported);
            
            // Her 5 mülkte bir durumu kontrol et ve kısa bir bekleme süresi ekle
            if (($imported + $errors) % 5 == 0) {
                sleep(1);
                if (!get_option('property_importer_is_running', false)) {
                    $this->log('info', 'İçe aktarma işlemi kullanıcı tarafından durduruldu.');
                    break;
                }
            }
        }

        $this->log('info', "İçe aktarma tamamlandı. Toplam: $total, Başarılı: $imported, Hata: $errors");
        update_option('property_importer_last_run', current_time('mysql'));
        update_option('property_importer_is_running', false);

        $this->send_webhook_notification([
            'event' => 'import_completed',
            'total' => $total,
            'imported' => $imported,
            'errors' => $errors
        ]);
    }

    private function import_property($property) {
        $property_data = [
            'post_title'    => !empty($property['title']) ? sanitize_text_field($property['title']) : 'Başlıksız Mülk - ' . uniqid(),
            'post_content'  => wp_kses_post($property['description']),
            'post_status'   => 'publish',
            'post_type'     => 'property',
            'post_author'   => 1,
        ];

        $post_id = wp_insert_post($property_data);

        if (!is_wp_error($post_id)) {
            update_post_meta($post_id, 'property_api_id', $property['id']);

            foreach ($property as $key => $value) {
                if ($key !== 'images') {
                    if ($key === 'REAL_HOMES_property_location') {
                        $location_parts = explode('/', $value);
                        $cleaned_location = implode(', ', array_map('trim', $location_parts));
                        update_post_meta($post_id, $key, $cleaned_location);
                        
                        // Ayrıca, il ve ilçe bilgilerini ayrı meta alanlara kaydedebilirsiniz
                        if (count($location_parts) >= 2) {
                            update_post_meta($post_id, 'property_city', trim($location_parts[0]));
                            update_post_meta($post_id, 'property_area', trim($location_parts[1]));
                        }
                    } else {
                    update_post_meta($post_id, $key, $value);
                    }
                }
            }

            if (!empty($property['type'])) {
                wp_set_object_terms($post_id, $property['type'], 'property-type');
            }
            if (!empty($property['status'])) {
                wp_set_object_terms($post_id, $property['status'], 'property-status');
            }

            if (isset($property['images']) && is_array($property['images'])) {
                $this->process_property_images($post_id, $property['images']);
            } else {
                $this->log('warning', 'Mülk için resim bulunamadı veya geçersiz format', ['property_id' => $post_id]);
            }

            $this->log('success', 'Yeni mülk başarıyla içe aktarıldı.', ['property_id' => $post_id]);
            return $post_id;
        } else {
            $this->log('error', 'Mülk içe aktarılamadı.', ['error' => $post_id->get_error_message()]);
            return false;
        }
    }

    private function process_property_images($property_id, $image_urls) {
        if (!is_array($image_urls)) {
            $this->log('error', 'Geçersiz resim URL\'leri: dizi bekleniyor', ['property_id' => $property_id]);
            return;
        }

        $attachment_ids = [];
        foreach ($image_urls as $image_url) {
            $attachment_id = $this->upload_and_resize_image($image_url, $property_id);
            if ($attachment_id) {
                $attachment_ids[] = $attachment_id;
            }
        }

        if (!empty($attachment_ids)) {
            delete_post_meta($property_id, 'REAL_HOMES_property_images');
            foreach ($attachment_ids as $attachment_id) {
                add_post_meta($property_id, 'REAL_HOMES_property_images', $attachment_id);
            }
            set_post_thumbnail($property_id, $attachment_ids[0]);
        } else {
            $this->log('warning', 'Hiçbir resim yüklenemedi', ['property_id' => $property_id]);
        }
    }

    private function upload_and_resize_image($image_url, $post_id) {
        if (empty($image_url)) {
            $this->log('error', 'Boş resim URL\'si', ['property_id' => $post_id]);
            return false;
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            $this->log('error', 'Resim indirilemedi: ' . $tmp->get_error_message(), ['property_id' => $post_id, 'image_url' => $image_url]);
            return false;
        }

        $file_array = [
            'name' => basename($image_url),
            'tmp_name' => $tmp
        ];

        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            $this->log('error', 'Resim yüklenemedi: ' . $attachment_id->get_error_message(), ['property_id' => $post_id, 'image_url' => $image_url]);
            return false;
        }

        $this->generate_image_sizes($attachment_id);

        return $attachment_id;
    }

    private function generate_image_sizes($attachment_id) {
        $image_sizes = [
            'property-detail-slider-image' => [1200, 680, true],
            'property-detail-slider-thumb' => [120, 68, true],
            'property-listing-image' => [488, 326, true]
        ];

        foreach ($image_sizes as $size_name => $size_data) {
            $image = wp_get_image_editor(get_attached_file($attachment_id));
            if (!is_wp_error($image)) {
                $image->resize($size_data[0], $size_data[1], $size_data[2]);
                $image->save($image->generate_filename($size_name));
            }
        }
    }

    private function log($level, $message, $context = []) {
        $logs = $this->get_logs();
        $logs[] = [
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message,
            'context' => is_array($context) ? json_encode($context) : $context
        ];
        update_option('property_importer_logs', array_slice($logs, -100)); // Son 100 log kaydını tutar
    }

    public function get_logs() {
        return get_option('property_importer_logs', []);
    }

    public function clear_logs() {
        $this->check_user_capabilities();
        check_ajax_referer('property_importer_nonce', 'nonce');

        update_option('property_importer_logs', []);
        wp_send_json_success('Loglar temizlendi.');
    }

    public function verify_nonce() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'property_importer_nonce')) {
            wp_send_json_error('Geçersiz güvenlik tokeni.');
        }
    }

    private function check_user_capabilities() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Bu işlemi gerçekleştirmek için yeterli izniniz yok.', 'property-importer'));
        }
    }

    private function get_cache($key) {
        return get_transient('property_importer_api_' . $key);
    }

    private function set_cache($key, $value, $expiration = 3600) {
        set_transient('property_importer_api_' . $key, $value, $expiration);
    }

    private function delete_cache($key) {
        delete_transient('property_importer_api_' . $key);
    }

    public function get_import_stats() {
        $this->check_user_capabilities();
        check_ajax_referer('property_importer_nonce', 'nonce');

        $total = get_option('property_importer_total_properties', 0);
        $imported = get_option('property_importer_imported_properties', 0);
        $queued = $total - $imported;

        wp_send_json_success([
            'total' => $total,
            'imported' => $imported,
            'queued' => $queued
        ]);
    }

    public function update_import_stats($total, $imported) {
        update_option('property_importer_total_properties', $total);
        update_option('property_importer_imported_properties', $imported);
    }

    public function register_webhook_settings() {
        register_setting('property_importer_webhook_settings', 'property_importer_webhook_url');
        add_settings_section('property_importer_webhook', 'Webhook Ayarları', null, 'property_importer_webhook_settings');
        add_settings_field('property_importer_webhook_url', 'Webhook URL', [$this, 'webhook_url_callback'], 'property_importer_webhook_settings', 'property_importer_webhook');
    }

    public function webhook_url_callback() {
        $webhook_url = get_option('property_importer_webhook_url', '');
        echo '<input type="text" name="property_importer_webhook_url" value="' . esc_attr($webhook_url) . '" class="regular-text">';
    }

    public function toggle_cron() {
        $this->check_user_capabilities();
        check_ajax_referer('property_importer_nonce', 'nonce');

        $current_status = wp_next_scheduled('property_importer_cron');

        if ($current_status) {
            wp_clear_scheduled_hook('property_importer_cron');
            $new_status = 'Pasif';
        } else {
            wp_schedule_event(time(), 'daily', 'property_importer_cron');
            $new_status = 'Aktif';
        }

        wp_send_json_success(['status' => $new_status]);
    }

    public function send_webhook_notification($data) {
        $webhook_url = get_option('property_importer_webhook_url', '');
        if (!empty($webhook_url)) {
            wp_remote_post($webhook_url, [
                'body' => json_encode($data),
                'headers' => ['Content-Type' => 'application/json']
            ]);
        }
    }

    public function activate_cron() {
        if (!wp_next_scheduled('property_importer_cron')) {
            wp_schedule_event(time(), 'daily', 'property_importer_cron');
        }
        if (!wp_next_scheduled('property_importer_sync_cron')) {
            wp_schedule_event(time(), 'hourly', 'property_importer_sync_cron');
        }
    }

    public function deactivate_cron() {
        $timestamp = wp_next_scheduled('property_importer_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'property_importer_cron');
        }
        $sync_timestamp = wp_next_scheduled('property_importer_sync_cron');
        if ($sync_timestamp) {
            wp_unschedule_event($sync_timestamp, 'property_importer_sync_cron');
        }
    }

    private function update_existing_property($post_id, $property_data) {
        $updated_post = array(
            'ID' => $post_id,
            'post_title' => $property_data['title'],
            'post_content' => $property_data['description'],
        );
        wp_update_post($updated_post);

        foreach ($property_data as $key => $value) {
            if ($key !== 'images') {
                update_post_meta($post_id, $key, $value);
            }
        }

        if (isset($property_data['images']) && is_array($property_data['images'])) {
            $this->process_property_images($post_id, $property_data['images']);
        }

        $this->log('info', 'Mülk güncellendi', ['property_id' => $post_id]);
    }

    private function delete_property($post_id) {
        wp_delete_post($post_id, true);
        $this->log('info', 'Mülk silindi', ['property_id' => $post_id]);
    }

    public function sync_properties() {
        $api = new Property_Importer_API();
        $properties = $api->fetch_properties();

        if (is_wp_error($properties)) {
            $this->log('error', 'API\'den veri alınamadı: ' . $properties->get_error_message());
            return;
        }

        $existing_properties = $this->get_existing_properties();
        $api_property_ids = array_column($properties, 'id');

        foreach ($properties as $property) {
            $post_id = $this->get_post_id_by_api_id($property['id']);
            if ($post_id) {
                $this->update_existing_property($post_id, $property);
            } else {
                $this->import_property($property);
            }
        }

        foreach ($existing_properties as $post_id => $api_id) {
            if (!in_array($api_id, $api_property_ids)) {
                $this->delete_property($post_id);
            }
        }

        $this->log('info', 'Mülk senkronizasyonu tamamlandı');
    }

    private function get_existing_properties() {
        $args = array(
            'post_type' => 'property',
            'posts_per_page' => -1,
            'fields' => 'ids',
        );
        $query = new WP_Query($args);
        $properties = array();

        foreach ($query->posts as $post_id) {
            $api_id = get_post_meta($post_id, 'property_api_id', true);
            if ($api_id) {
                $properties[$post_id] = $api_id;
            }
        }

        return $properties;
    }

    private function get_post_id_by_api_id($api_id) {
        $args = array(
            'post_type' => 'property',
            'meta_key' => 'property_api_id',
            'meta_value' => $api_id,
            'posts_per_page' => 1,
            'fields' => 'ids',
        );
        $query = new WP_Query($args);

        return $query->posts ? $query->posts[0] : null;
    }
}

function property_importer_init() {
    return PropertyImporter::get_instance();
}

property_importer_init();