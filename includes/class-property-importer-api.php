<?php
if (!defined('ABSPATH')) exit;

class Property_Importer_API {
    private $api_url;

    public function __construct() {
        $this->api_url = get_option('property_importer_api_url', '');
    }

    private function get_cache($key) {
        return get_transient('property_importer_api_' . $key);
    }

    private function set_cache($key, $value, $expiration = 3600) {
        set_transient('property_importer_api_' . $key, $value, $expiration);
    }

    public function fetch_properties() {
        $cached_data = $this->get_cache('properties');
        if ($cached_data !== false) return $cached_data;
        
        $all_properties = $this->fetch_all_pages();
        
        if (is_wp_error($all_properties)) {
            return $all_properties;
        }

        $sanitized_data = array_map([$this, 'sanitize_property_data'], $all_properties);
        $this->set_cache('properties', $sanitized_data, 1800);

        return $sanitized_data;
    }

    private function fetch_all_pages() {
        $all_properties = [];
        $page = 1;
        $per_page = 20;

        do {
            $url = add_query_arg(['page' => $page, 'per_page' => $per_page], $this->api_url);
            $response = wp_remote_get($url);
            
            if (is_wp_error($response)) {
                return new WP_Error('api_error', 'API\'ye bağlanırken hata oluştu: ' . $response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('json_error', 'JSON verisi ayrıştırılamadı. Hata: ' . json_last_error_msg());
            }

            if (!is_array($data) || empty($data)) {
                break;
            }

            $all_properties = array_merge($all_properties, $data);
            $page++;
        } while (count($data) == $per_page);

        return $all_properties;
    }

    public function fetch_property_by_id($api_id) {
        $url = add_query_arg(['id' => $api_id], $this->api_url);
        $response = wp_remote_get($url);
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'API\'ye bağlanırken hata oluştu: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'JSON verisi ayrıştırılamadı. Hata: ' . json_last_error_msg());
        }

        return $this->sanitize_property_data($data);
    }

    public function sanitize_property_data($property) {
        $sanitized = [
            'title' => sanitize_text_field($property['title']),
            'description' => wp_kses_post($property['description']),
            'REAL_HOMES_property_price' => $this->clean_price($property['price']),
            'REAL_HOMES_property_location' => $this->sanitize_location($property['location']),
            'type' => sanitize_text_field($property['type']),
            'status' => sanitize_text_field($property['status']),
        ];

        $sanitized = $this->sanitize_attributes($property, $sanitized);
        $sanitized = $this->sanitize_features($property, $sanitized);
        $sanitized = $this->sanitize_images($property, $sanitized);

        return $sanitized;
    }

    private function sanitize_attributes($property, $sanitized) {
        if (!empty($property['attributes']) && is_array($property['attributes'])) {
            foreach ($property['attributes'] as $key => $value) {
                $clean_key = $this->clean_attribute_key($key);
                $sanitized[$clean_key] = sanitize_text_field($value);
            }
        }
        return $sanitized;
    }

    private function sanitize_features($property, $sanitized) {
        if (!empty($property['features']) && is_array($property['features'])) {
            $sanitized['features'] = array_map('sanitize_text_field', $property['features']);
        }
        return $sanitized;
    }

    private function sanitize_images($property, $sanitized) {
        if (!empty($property['images']) && is_array($property['images'])) {
            $sanitized['images'] = array_map('esc_url', array_filter($property['images']));
        } else {
            $sanitized['images'] = [];
        }
        return $sanitized;
    }

    private function clean_price($price) {
        return preg_replace('/[^0-9]/', '', str_replace(['TL', ' ', '.', ','], '', $price));
    }

    private function clean_attribute_key($key) {
        return strtolower(str_replace(' ', '_', $key));
    }

    private function sanitize_location($location) {
        if (is_array($location) && isset($location['lat']) && isset($location['lng'])) {
            return $location['lat'] . ',' . $location['lng'];
        } elseif (is_string($location)) {
            $parts = explode('/', $location);
            return implode(', ', array_map('trim', $parts));
        }
        return '';
    }
}