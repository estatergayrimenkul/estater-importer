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

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
        add_action('property_importer_sync_cron', [$this, 'sync_properties']);
    }

    public function init() {
        $this->define_constants();
        $this->load_dependencies();
        $this->register_hooks();
        $this->register_webhook_endpoint();
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
        add_action('property_importer_cron', [$this, 'run_import']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_init', [$this, 'register_webhook_settings']);
        add_action('property_importer_sync_cron', [$this, 'sync_properties']);
        add_action('wp_ajax_regenerate_webhook_secret', [$this, 'regenerate_webhook_secret']);
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
        register_setting('property_importer_settings', 'property_importer_api_url', [
            'sanitize_callback' => [$this, 'sanitize_api_url'],
        ]);
        add_settings_section('property_importer_main', 'API Ayarları', null, 'property_importer_settings');
        add_settings_field('property_importer_api_url', 'API URL', [$this, 'api_url_callback'], 'property_importer_settings', 'property_importer_main');
    }

    public function api_url_callback() {
        $api_url = get_option('property_importer_api_url', '');
        echo '<input type="text" name="property_importer_api_url" value="' . esc_attr($api_url) . '" class="regular-text">';
    }

    public function sanitize_api_url($value) {
        $old_value = get_option('property_importer_api_url');
        if ($old_value !== $value) {
            $this->clear_api_cache();
        }
        return esc_url_raw($value);
    }

    private function clear_api_cache() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_property_importer_api_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_property_importer_api_%'");
    }

    public function start_import() {
        error_log('start_import metodu başladı');
        
        // AJAX isteği için nonce kontrolü yap
        if (defined('DOING_AJAX') && DOING_AJAX) {
            check_ajax_referer('property_importer_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                error_log('Yetkisiz erişim');
                wp_send_json_error('Yetkisiz erişim.');
            }
        }
        
        update_option('property_importer_is_running', true);
        wp_schedule_single_event(time(), 'property_importer_cron');
        
        error_log('start_import metodu tamamlandı');
        
        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json_success([
                'message' => 'İçe aktarma başlatıldı.',
                'is_running' => true
            ]);
        }
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

        wp_send_json_success([
            'message' => 'İçe aktarma durduruldu.',
            'logs' => $this->get_logs(),
            'is_running' => false,
            'progress' => get_option('property_importer_progress', 0)
        ]);
    }

    public function get_import_progress() {
        check_ajax_referer('property_importer_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Yetkisiz erişim.');
        }
        $is_running = get_option('property_importer_is_running', false);
        wp_send_json_success([
            'is_running' => $is_running
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
            $this->log('info', 'İçe aktarma işlemi durduruldu veya başlatılmadı.');
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

        $existing_properties = $this->get_existing_properties();
        $api_property_ids = array_column($properties, 'id');
        $this->log('info', 'API\'den gelen mülk ID\'leri alındı.', ['count' => count($api_property_ids)]);

        foreach ($properties as $property) {
            if (!get_option('property_importer_is_running', false)) {
                $this->log('info', 'İçe aktarma işlemi kullanıcı tarafından durduruldu.');
                break;
            }

            try {
                if ($this->import_property($property, $api_property_ids)) {
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

            if (($imported + $errors) % 5 == 0) {
                sleep(1);
            }
        }

        // API'de olmayan mülkleri sil
        $properties_to_delete = array_diff($existing_properties, $api_property_ids);
        foreach ($properties_to_delete as $post_id => $api_id) {
            $this->delete_property($post_id);
            $this->log('info', 'API\'de bulunmayan mülk silindi.', ['property_id' => $post_id, 'api_id' => $api_id]);
        }

        update_option('property_importer_is_running', false);
        $this->log('info', "çe aktarma tamamlandı. Toplam: $total, Başarılı: $imported, Hata: $errors, Silinen: " . count($properties_to_delete));
        update_option('property_importer_last_run', current_time('mysql'));

        $this->send_webhook_notification([
            'event' => 'import_completed',
            'total' => $total,
            'imported' => $imported,
            'errors' => $errors,
            'deleted' => count($properties_to_delete)
        ]);
    }

    private function import_property($property, $api_property_ids) {
        $existing_properties = $this->get_existing_properties();
        $property_id = $property['id'];

        if (in_array($property_id, $existing_properties)) {
            $post_id = array_search($property_id, $existing_properties);
            $this->update_existing_property($post_id, $property);
            $this->log('info', 'Mevcut mülk güncellendi.', ['property_id' => $post_id]);
            return true;
        }

        $this->log('info', 'Yeni mülk ekleniyor.', ['property_id' => $property_id]);
            
        $post_id = wp_insert_post([
            'post_title'    => !empty($property['title']) ? sanitize_text_field($property['title']) : 'Başlıksız Mülk - ' . uniqid(),
            'post_content'  => wp_kses_post($property['description']),
            'post_status'   => 'publish',
            'post_type'     => 'property',
        ]);

        if (is_wp_error($post_id)) {
            $this->log('error', 'Mülk içe aktarılamadı.', ['error' => $post_id->get_error_message()]);
            return false;
        }

        update_post_meta($post_id, 'property_api_id', $property['id']);

        $this->update_property_meta($post_id, $property);
        $this->process_taxonomies($post_id, $property);
        $this->process_property_images($post_id, $property['images']);

        $this->log('success', 'Yeni mülk başarıyla içe aktarıldı.', ['property_id' => $post_id]);
        return $post_id;
    }

    // Mülkü ve ilişkili tüm verileri silen yardımcı fonksiyon
    private function delete_property($post_id) {
        // Mülke ait görselleri sil
        $attachment_ids = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_parent' => $post_id,
            'fields' => 'ids',
        ]);
        foreach ($attachment_ids as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }

        // Mülke ait meta verileri sil
        $meta_keys = get_post_custom_keys($post_id);
        if ($meta_keys) {
            foreach ($meta_keys as $meta_key) {
                delete_post_meta($post_id, $meta_key);
            }
        }

        // Mülke ait taksonomileri sil
        $taxonomies = get_object_taxonomies('property');
        foreach ($taxonomies as $taxonomy) {
            wp_delete_object_term_relationships($post_id, $taxonomy);
        }

        // Mülkü sil
        wp_delete_post($post_id, true);
    }

    public function sync_properties() {
        $api = new Property_Importer_API();
        $api_properties = $api->fetch_properties();

        if (is_wp_error($api_properties)) {
            $this->log('error', 'API\'den veri alınamadı: ' . $api_properties->get_error_message());
            return;
        }

        $existing_properties = $this->get_existing_properties();
        $api_property_ids = array_column($api_properties, 'id');

        // API'den gelen mülkleri güncelle veya ekle
        foreach ($api_properties as $property) {
            $post_id = $this->get_post_id_by_api_id($property['id']);
            if ($post_id) {
                $this->update_existing_property($post_id, $property);
            } else {
                $this->import_property($property, $api_property_ids);
            }
        }

        // API'de olmayan mülkleri sil
        $properties_to_delete = array_diff($existing_properties, $api_property_ids);
        foreach ($properties_to_delete as $post_id => $api_id) {
            wp_delete_post($post_id, true);
            $this->log('info', 'Mülk silindi', ['property_id' => $post_id]);
        }

        $this->log('info', 'Mülk senkronizasyonu tamamlandı');
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

    public function register_webhook_settings() {
        register_setting('property_importer_webhook_settings', 'property_importer_webhook_url');
        register_setting('property_importer_webhook_settings', 'property_importer_webhook_secret');
        add_settings_section('property_importer_webhook', 'Webhook Ayarları', null, 'property_importer_webhook_settings');
        add_settings_field('property_importer_webhook_url', 'Webhook URL', [$this, 'webhook_url_callback'], 'property_importer_webhook_settings', 'property_importer_webhook');
        add_settings_field('property_importer_webhook_secret', 'Webhook Secret', [$this, 'webhook_secret_callback'], 'property_importer_webhook_settings', 'property_importer_webhook');
    }

    public function webhook_url_callback() {
        $webhook_url = get_option('property_importer_webhook_url', '');
        echo '<input type="text" name="property_importer_webhook_url" value="' . esc_attr($webhook_url) . '" class="regular-text">';
    }

    public function webhook_secret_callback() {
        $webhook_secret = get_option('property_importer_webhook_secret', '');
        echo '<input type="text" name="property_importer_webhook_secret" value="' . esc_attr($webhook_secret) . '" class="regular-text">';
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
        if (!wp_next_scheduled('property_importer_sync_cron')) {
            wp_schedule_event(time(), 'hourly', 'property_importer_sync_cron');
        }
    }

    public function deactivate_cron() {
        $timestamp = wp_next_scheduled('property_importer_sync_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'property_importer_sync_cron');
        }
    }

    private function get_existing_properties() {
        $args = [
            'post_type'      => 'property',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];
        $query = new WP_Query($args);
        $properties = [];

        foreach ($query->posts as $post_id) {
            $api_id = get_post_meta($post_id, 'property_api_id', true);
            if ($api_id) {
                $properties[$post_id] = $api_id;
            }
        }

        return $properties;
    }

    private function get_post_id_by_api_id($api_id) {
        $args = [
            'post_type'      => 'property',
            'meta_key'       => 'property_api_id',
            'meta_value'     => $api_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ];
        $query = new WP_Query($args);

        return $query->posts ? $query->posts[0] : null;
    }

    private function update_existing_property($post_id, $property) {
        wp_update_post([
            'ID'           => $post_id,
            'post_title'   => !empty($property['title']) ? sanitize_text_field($property['title']) : 'Başlıksız Mülk - ' . uniqid(),
            'post_content' => wp_kses_post($property['description']),
        ]);

        // Fiyat güncelleme
        if (isset($property['REAL_HOMES_property_price'])) {
            update_post_meta($post_id, 'REAL_HOMES_property_price', $property['REAL_HOMES_property_price']);
        }

        $this->update_property_meta($post_id, $property);
        $this->process_taxonomies($post_id, $property);
        $this->process_property_images($post_id, $property['images']);

        $this->log('success', 'Mevcut mülk güncellendi.', ['property_id' => $post_id]);
        return $post_id;
    }

    private function process_taxonomies($post_id, $property) {
        if (!empty($property['type'])) {
            wp_set_object_terms($post_id, $property['type'], 'property-type');
        }
        if (!empty($property['status'])) {
            wp_set_object_terms($post_id, $property['status'], 'property-status');
        }
    }

    private function update_property_meta($post_id, $property) {
        foreach ($property as $key => $value) {
            if ($key !== 'images' && $key !== 'title' && $key !== 'description') {
                update_post_meta($post_id, $key, $value);
            }
        }
    }

    private function register_webhook_endpoint() {
        add_action('rest_api_init', function () {
            register_rest_route('property-importer/v1', '/webhook', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_webhook'],
                'permission_callback' => '__return_true', // Her zaman true döndürür
            ]);
        });
    }

    public function handle_webhook(WP_REST_Request $request) {
        error_log('Webhook tetiklendi ve içe aktarma başlatıldı.');
        
        // AJAX nonce kontrolünü atlayarak doğrudan içe aktarma işlemini başlat
        update_option('property_importer_is_running', true);
        wp_schedule_single_event(time(), 'property_importer_cron');
        
        return new WP_REST_Response(['message' => 'İçe aktarma başlatıldı'], 200);
    }

    public function generate_webhook_secret($length = 32) {
        return bin2hex(random_bytes($length));
    }

    public function regenerate_webhook_secret() {
        $this->check_user_capabilities();
        check_ajax_referer('property_importer_nonce', 'nonce');

        $new_secret = $this->generate_webhook_secret();
        update_option('property_importer_webhook_secret', $new_secret);

        wp_send_json_success(['new_secret' => $new_secret]);
    }
}

function property_importer_init() {
    return PropertyImporter::get_instance();
}

property_importer_init();