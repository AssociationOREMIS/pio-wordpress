<?php

/**
 * Plugin Name: PIO OREMIS API
 * Description: Display members from the PIO API (department teams) via shortcodes.
 * Version: 0.1.0
 * Author: OREMIS
 * Text Domain: pio-oremis
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PIO_OREMIS_VERSION', '0.1.0');
define('PIO_OREMIS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PIO_OREMIS_PLUGIN_URL', plugin_dir_url(__FILE__));
if (!defined('PIO_OREMIS_API_TOKEN')) {
    define('PIO_OREMIS_API_TOKEN', '');
}

function pio_oremis_default_settings()
{
    return array(
        'base_url' => 'https://pio.oremis.dev/api',
        'api_token' => '',
        'cache_ttl' => 900,
        'default_team_code' => '',
    );
}

function pio_oremis_get_settings()
{
    $defaults = pio_oremis_default_settings();
    $settings = get_option('pio_oremis_settings', array());
    if (!is_array($settings)) {
        $settings = array();
    }
    return wp_parse_args($settings, $defaults);
}

function pio_oremis_get_api_token()
{
    if (defined('PIO_OREMIS_API_TOKEN') && PIO_OREMIS_API_TOKEN !== '') {
        return PIO_OREMIS_API_TOKEN;
    }
    $settings = pio_oremis_get_settings();
    return isset($settings['api_token']) ? $settings['api_token'] : '';
}

function pio_oremis_is_allowed_host($host)
{
    $host = strtolower($host);
    $allowed_hosts = apply_filters('pio_oremis_allowed_hosts', array(
        'pio.oremis.dev',
        'pio.oremis.fr',
    ));
    foreach ($allowed_hosts as $allowed) {
        $allowed = strtolower(trim($allowed));
        if ($allowed === '') {
            continue;
        }
        if ($host === $allowed) {
            return true;
        }
        if (substr($allowed, 0, 1) !== '.' && substr($host, -strlen('.' . $allowed)) === '.' . $allowed) {
            return true;
        }
    }
    return false;
}

function pio_oremis_sanitize_settings($input)
{
    $defaults = pio_oremis_default_settings();
    $output = array();

    $output['base_url'] = isset($input['base_url']) ? esc_url_raw(trim($input['base_url'])) : $defaults['base_url'];
    if (defined('PIO_OREMIS_API_TOKEN') && PIO_OREMIS_API_TOKEN !== '') {
        $output['api_token'] = '';
    } else {
        $output['api_token'] = isset($input['api_token']) ? sanitize_text_field($input['api_token']) : '';
    }
    $output['cache_ttl'] = isset($input['cache_ttl']) ? max(0, intval($input['cache_ttl'])) : $defaults['cache_ttl'];
    $output['default_team_code'] = isset($input['default_team_code']) ? sanitize_text_field($input['default_team_code']) : '';

    return $output;
}

function pio_oremis_admin_menu()
{
    add_options_page(
        'PIO OREMIS API',
        'PIO OREMIS',
        'manage_options',
        'pio-oremis',
        'pio_oremis_render_settings_page'
    );
}
add_action('admin_menu', 'pio_oremis_admin_menu');

function pio_oremis_admin_init()
{
    register_setting('pio_oremis_options', 'pio_oremis_settings', array(
        'sanitize_callback' => 'pio_oremis_sanitize_settings',
    ));

    add_settings_section(
        'pio_oremis_main',
        'API Settings',
        '__return_false',
        'pio-oremis'
    );

    add_settings_field(
        'pio_oremis_base_url',
        'Base URL',
        'pio_oremis_field_base_url',
        'pio-oremis',
        'pio_oremis_main'
    );

    add_settings_field(
        'pio_oremis_api_token',
        'API Token',
        'pio_oremis_field_api_token',
        'pio-oremis',
        'pio_oremis_main'
    );

    add_settings_field(
        'pio_oremis_cache_ttl',
        'Cache TTL (seconds)',
        'pio_oremis_field_cache_ttl',
        'pio-oremis',
        'pio_oremis_main'
    );

    add_settings_field(
        'pio_oremis_default_team_code',
        'Default Team Code',
        'pio_oremis_field_default_team_code',
        'pio-oremis',
        'pio_oremis_main'
    );
}
add_action('admin_init', 'pio_oremis_admin_init');

function pio_oremis_field_base_url()
{
    $settings = pio_oremis_get_settings();
    printf(
        '<input type="url" class="regular-text" name="pio_oremis_settings[base_url]" value="%s" />',
        esc_attr($settings['base_url'])
    );
    echo '<p class="description">Example: https://pio.oremis.dev/api (host must be allowed)</p>';
}

function pio_oremis_field_api_token()
{
    $settings = pio_oremis_get_settings();
    $disabled = '';
    if (defined('PIO_OREMIS_API_TOKEN') && PIO_OREMIS_API_TOKEN !== '') {
        $disabled = 'disabled';
    }
    printf(
        '<input type="password" class="regular-text" name="pio_oremis_settings[api_token]" value="%s" autocomplete="off" %s />',
        esc_attr($settings['api_token']),
        esc_attr($disabled)
    );
    if ($disabled !== '') {
        echo '<p class="description">Token set via wp-config.php (PIO_OREMIS_API_TOKEN).</p>';
    } else {
        echo '<p class="description">Leave empty if the endpoint is public.</p>';
    }
}

function pio_oremis_field_cache_ttl()
{
    $settings = pio_oremis_get_settings();
    printf(
        '<input type="number" min="0" class="small-text" name="pio_oremis_settings[cache_ttl]" value="%s" />',
        esc_attr($settings['cache_ttl'])
    );
    echo '<p class="description">0 disables caching.</p>';
}

function pio_oremis_field_default_team_code()
{
    $settings = pio_oremis_get_settings();
    printf(
        '<input type="text" class="regular-text" name="pio_oremis_settings[default_team_code]" value="%s" />',
        esc_attr($settings['default_team_code'])
    );
    echo '<p class="description">Used when the shortcode has no code attribute.</p>';
}

function pio_oremis_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    echo '<div class="wrap">';
    echo '<h1>PIO OREMIS API</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('pio_oremis_options');
    do_settings_sections('pio-oremis');
    submit_button();
    echo '</form>';
    echo '<hr />';
    echo '<h2>Shortcode</h2>';
    echo '<p>Use <code>[pio_team code="CA-OFFICIER"]</code> to display members.</p>';
    echo '</div>';
}

function pio_oremis_enqueue_assets()
{
    wp_register_style(
        'pio-oremis',
        PIO_OREMIS_PLUGIN_URL . 'assets/pio-oremis.css',
        array(),
        PIO_OREMIS_VERSION
    );
}
add_action('wp_enqueue_scripts', 'pio_oremis_enqueue_assets');

function pio_oremis_fetch_team_users($team_code, $force_refresh = false)
{
    $settings = pio_oremis_get_settings();
    $team_code = trim($team_code);
    if ($team_code === '') {
        return new WP_Error('pio_oremis_missing_code', 'Missing team code.');
    }

    $cache_ttl = intval($settings['cache_ttl']);
    $cache_key = 'pio_oremis_team_' . md5(strtolower($team_code));

    if (!$force_refresh && $cache_ttl > 0) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $base_url = untrailingslashit($settings['base_url']);
    $scheme = strtolower((string) parse_url($base_url, PHP_URL_SCHEME));
    $host = parse_url($base_url, PHP_URL_HOST);
    if ($scheme !== 'https') {
        return new WP_Error('pio_oremis_invalid_scheme', 'Base URL must use HTTPS.');
    }
    if (!$host || !pio_oremis_is_allowed_host($host)) {
        return new WP_Error('pio_oremis_invalid_host', 'Base URL host is not allowed.');
    }
    $url = $base_url . '/v1/department-teams/' . rawurlencode($team_code) . '/users';

    $headers = array(
        'Accept' => 'application/json',
    );
    $api_token = pio_oremis_get_api_token();
    if (!empty($api_token)) {
        $headers['Authorization'] = 'Bearer ' . $api_token;
    }

    $response = wp_safe_remote_get($url, array(
        'headers' => $headers,
        'timeout' => 10,
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('pio_oremis_http_error', 'Unexpected response code: ' . $code);
    }

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
        return new WP_Error('pio_oremis_invalid_response', 'Invalid API response.');
    }

    if ($cache_ttl > 0) {
        set_transient($cache_key, $data['data'], $cache_ttl);
    }

    return $data['data'];
}

function pio_oremis_member_initials($first_name, $last_name)
{
    if (function_exists('mb_substr')) {
        $first = $first_name !== '' ? mb_strtoupper(mb_substr($first_name, 0, 1)) : '';
        $last = $last_name !== '' ? mb_strtoupper(mb_substr($last_name, 0, 1)) : '';
    } else {
        $first = $first_name !== '' ? strtoupper(substr($first_name, 0, 1)) : '';
        $last = $last_name !== '' ? strtoupper(substr($last_name, 0, 1)) : '';
    }
    return trim($first . $last);
}

function pio_oremis_upper_initial($value)
{
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'));
    }

    return strtoupper(substr($value, 0, 1));
}

function pio_oremis_member_order($member)
{
    $positions = isset($member['staff_positions']) && is_array($member['staff_positions']) ? $member['staff_positions'] : array();
    $min = null;
    foreach ($positions as $pos) {
        if (!isset($pos['order'])) {
            continue;
        }
        $order = intval($pos['order']);
        if ($min === null || $order < $min) {
            $min = $order;
        }
    }
    return $min === null ? 9999 : $min;
}

function pio_oremis_shortcode_team($atts)
{
    $settings = pio_oremis_get_settings();
    $atts = shortcode_atts(array(
        'code' => $settings['default_team_code'],
        'show_email' => '0',
        'show_cib' => '0',
        'layout' => 'cards',
        'columns' => '3',
        'class' => '',
        'limit' => '0',
        'debug' => '0',
    ), $atts, 'pio_team');

    $team_code = sanitize_text_field($atts['code']);
    $force_refresh = false;
    if (current_user_can('manage_options') && isset($_GET['pio_refresh']) && $_GET['pio_refresh'] === '1') {
        $force_refresh = true;
    }

    $members = pio_oremis_fetch_team_users($team_code, $force_refresh);
    if (is_wp_error($members)) {
        if (current_user_can('manage_options')) {
            return '<div class="pio-team-error">' . esc_html($members->get_error_message()) . '</div>';
        }
        return '';
    }

    if (!is_array($members) || count($members) === 0) {
        return '<div class="pio-team-empty">No members found.</div>';
    }

    usort($members, function ($a, $b) {
        $order_a = pio_oremis_member_order($a);
        $order_b = pio_oremis_member_order($b);
        if ($order_a === $order_b) {
            $last_a = isset($a['last_name']) ? strtolower($a['last_name']) : '';
            $last_b = isset($b['last_name']) ? strtolower($b['last_name']) : '';
            if ($last_a === $last_b) {
                $first_a = isset($a['first_name']) ? strtolower($a['first_name']) : '';
                $first_b = isset($b['first_name']) ? strtolower($b['first_name']) : '';
                return $first_a <=> $first_b;
            }
            return $last_a <=> $last_b;
        }
        return $order_a <=> $order_b;
    });

    $limit = max(0, intval($atts['limit']));
    if ($limit > 0) {
        $members = array_slice($members, 0, $limit);
    }

    wp_enqueue_style('pio-oremis');

    $layout = $atts['layout'] === 'list' ? 'list' : 'cards';
    $columns = max(1, min(6, intval($atts['columns'])));
    $extra_class = sanitize_html_class($atts['class']);

    $wrapper_classes = array('pio-team', 'pio-team--' . $layout, 'pio-team--cols-' . $columns);
    if ($extra_class !== '') {
        $wrapper_classes[] = $extra_class;
    }

    $html = '<div class="' . esc_attr(implode(' ', $wrapper_classes)) . '">';

    foreach ($members as $member) {
        $first_name = isset($member['first_name']) ? sanitize_text_field($member['first_name']) : '';
        $last_name = isset($member['last_name']) ? sanitize_text_field($member['last_name']) : '';
        $email = '';
        $cib = isset($member['cib']) ? sanitize_text_field($member['cib']) : '';
        $img_url = isset($member['profile_img_url']) ? esc_url_raw($member['profile_img_url']) : '';
        $positions = isset($member['staff_positions']) && is_array($member['staff_positions']) ? $member['staff_positions'] : array();

        if (!empty($positions)) {
            usort($positions, function ($a, $b) {
                $order_a = isset($a['order']) ? intval($a['order']) : 9999;
                $order_b = isset($b['order']) ? intval($b['order']) : 9999;
                if ($order_a === $order_b) {
                    $name_a = isset($a['name']) ? strtolower($a['name']) : '';
                    $name_b = isset($b['name']) ? strtolower($b['name']) : '';
                    return $name_a <=> $name_b;
                }
                return $order_a <=> $order_b;
            });

            foreach ($positions as $pos) {
                $position_code = isset($pos['code']) ? sanitize_text_field($pos['code']) : '';
                if ($position_code !== '') {
                    $email = sanitize_email(strtolower($position_code) . '@oremis.fr');
                    break;
                }
            }
        }

        if ($email === '') {
            $email = isset($member['email']) ? sanitize_email($member['email']) : '';
        }

        $last_initial = pio_oremis_upper_initial($last_name);
        $full_name = trim($first_name . ' ' . ($last_initial !== '' ? $last_initial . '.' : ''));
        $initials = pio_oremis_member_initials($first_name, $last_name);

        $html .= '<div class="pio-member">';
        if ($img_url) {
            $html .= '<div class="pio-avatar"><img src="' . esc_url($img_url) . '" alt="' . esc_attr($full_name) . '" loading="lazy" decoding="async" referrerpolicy="no-referrer" /></div>';
        } else {
            $html .= '<div class="pio-avatar pio-avatar--placeholder"><span>' . esc_html($initials) . '</span></div>';
        }
        $html .= '<div class="pio-member-body">';
        $html .= '<div class="pio-member-name">' . esc_html($full_name) . '</div>';

        if (!empty($positions)) {
            $html .= '<ul class="pio-member-roles">';
            foreach ($positions as $pos) {
                $role_name = isset($pos['name']) ? sanitize_text_field($pos['name']) : '';
                if ($role_name !== '') {
                    $html .= '<li>' . esc_html($role_name) . '</li>';
                }
            }
            $html .= '</ul>';
        }

        if ($atts['show_cib'] === '1' && $cib !== '') {
            $html .= '<div class="pio-member-cib">' . esc_html($cib) . '</div>';
        }

        if ($atts['show_email'] === '1' && $email !== '') {
            $html .= '<div class="pio-member-email">Email : <a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a></div>';
        }

        $html .= '</div>';
        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
}
add_shortcode('pio_team', 'pio_oremis_shortcode_team');
