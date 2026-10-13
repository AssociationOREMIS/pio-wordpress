<?php

/**
 * OREMIS Chat: the chat bubble on the website, served by the chat itself (embed.js).
 *
 * The bubble is isolated from the theme (shadow DOM) and opens the chat in an iframe, so WordPress only
 * adds one script tag. Modes: off, administrators only (to try it on the real site), everyone.
 */

if (!defined('ABSPATH')) {
    exit;
}

function pio_oremis_chat_default_settings()
{
    return array(
        'mode' => 'off',
        'url' => 'https://chat.oremis.fr',
        'contact_url' => home_url('/contactez-nous/'),
        'hidden_pages' => '',
    );
}

function pio_oremis_chat_get_settings()
{
    $settings = get_option('pio_oremis_chat_settings', array());
    if (!is_array($settings)) {
        $settings = array();
    }
    return wp_parse_args($settings, pio_oremis_chat_default_settings());
}

/**
 * Pages (slugs) where the bubble is left out, e.g. a page that already embeds the chat.
 */
function pio_oremis_chat_hidden_slugs($settings)
{
    $slugs = preg_split('/[\s,]+/', strtolower((string) $settings['hidden_pages']));
    return array_values(array_filter(array_map('sanitize_title', $slugs)));
}

function pio_oremis_chat_is_visible()
{
    $settings = pio_oremis_chat_get_settings();
    if ($settings['mode'] === 'off' || strpos($settings['url'], 'https://') !== 0) {
        return false;
    }
    if ($settings['mode'] === 'admins' && !current_user_can(pio_oremis_settings_capability())) {
        return false;
    }

    $hidden = pio_oremis_chat_hidden_slugs($settings);
    if ($hidden && is_singular()) {
        $post = get_queried_object();
        if ($post && in_array($post->post_name, $hidden, true)) {
            return false;
        }
    }
    return true;
}

function pio_oremis_chat_enqueue()
{
    if (!pio_oremis_chat_is_visible()) {
        return;
    }
    $settings = pio_oremis_chat_get_settings();
    // No version: embed.js is versioned by the chat itself, and a ?ver= would defeat its cache.
    wp_enqueue_script('oremis-chat', untrailingslashit($settings['url']) . '/embed.js', array(), null, true);
}
add_action('wp_enqueue_scripts', 'pio_oremis_chat_enqueue');

/**
 * embed.js reads its own tag (document.currentScript): it must stay a classic, deferred script.
 */
function pio_oremis_chat_script_tag($tag, $handle, $src)
{
    if ($handle !== 'oremis-chat') {
        return $tag;
    }
    $settings = pio_oremis_chat_get_settings();
    $contact = $settings['contact_url'] !== '' ? ' data-contact-url="' . esc_url($settings['contact_url']) . '"' : '';
    return '<script src="' . esc_url($src) . '" id="oremis-chat-js"' . $contact . ' defer></script>' . "\n";
}
add_filter('script_loader_tag', 'pio_oremis_chat_script_tag', 10, 3);

/**
 * [oremis_chat_link] A link to the full chat page, independent from WordPress,
 * for pages such as "Signaler une situation de harcèlement".
 */
function pio_oremis_shortcode_chat_link($atts)
{
    $atts = shortcode_atts(array(
        'text' => 'Discuter avec un bénévole',
        'class' => '',
    ), $atts, 'oremis_chat_link');

    if (!pio_oremis_chat_is_visible()) {
        return '';
    }
    $settings = pio_oremis_chat_get_settings();
    $classes = trim('pio-chat-link ' . sanitize_html_class($atts['class']));
    return '<a class="' . esc_attr($classes) . '" href="' . esc_url(trailingslashit($settings['url'])) . '" target="_blank" rel="noopener">'
        . esc_html($atts['text'])
        . '<span class="pio-sr-only"> (s’ouvre dans un nouvel onglet)</span></a>';
}
add_shortcode('oremis_chat_link', 'pio_oremis_shortcode_chat_link');

// --------------------------------------------------------------------------
// Settings: OREMIS > Chat
// --------------------------------------------------------------------------

function pio_oremis_chat_admin_menu()
{
    add_submenu_page(
        'pio-oremis',
        'Chat',
        'Chat',
        pio_oremis_settings_capability(),
        'pio-oremis-chat',
        'pio_oremis_render_chat_settings_page'
    );
}
// After pio_oremis_admin_menu(), which creates the parent OREMIS menu.
add_action('admin_menu', 'pio_oremis_chat_admin_menu', 20);

function pio_oremis_sanitize_chat_settings($input)
{
    $input = is_array($input) ? $input : array();
    $defaults = pio_oremis_chat_default_settings();

    $mode = isset($input['mode']) ? (string) $input['mode'] : 'off';
    $url = isset($input['url']) ? untrailingslashit(esc_url_raw(trim($input['url']), array('https'))) : '';
    if ($url === '') {
        add_settings_error('pio_oremis_chat_settings', 'pio_oremis_chat_url', 'The chat address must start with https://. The default one was kept.');
        $url = $defaults['url'];
    }

    return array(
        'mode' => in_array($mode, array('off', 'admins', 'everyone'), true) ? $mode : 'off',
        'url' => $url,
        'contact_url' => isset($input['contact_url']) ? esc_url_raw(trim($input['contact_url'])) : '',
        'hidden_pages' => isset($input['hidden_pages']) ? implode("\n", pio_oremis_chat_hidden_slugs($input)) : '',
    );
}

function pio_oremis_chat_admin_init()
{
    register_setting('pio_oremis_chat', 'pio_oremis_chat_settings', array(
        'sanitize_callback' => 'pio_oremis_sanitize_chat_settings',
    ));
}
add_action('admin_init', 'pio_oremis_chat_admin_init');

/**
 * Whether the chat answers its health check, as a short label for the settings page.
 */
function pio_oremis_chat_health($url)
{
    $response = wp_safe_remote_get(untrailingslashit($url) . '/api/health', array('timeout' => 5));
    if (is_wp_error($response)) {
        return array(false, $response->get_error_message());
    }
    $code = wp_remote_retrieve_response_code($response);
    return array($code === 200, 'HTTP ' . $code);
}

function pio_oremis_render_chat_settings_page()
{
    if (!current_user_can(pio_oremis_settings_capability())) {
        return;
    }
    $settings = pio_oremis_chat_get_settings();
    $modes = array(
        'off' => 'Off',
        'admins' => 'Administrators only (to try it on the real site)',
        'everyone' => 'Everyone',
    );

    echo '<div class="wrap"><h1>Chat</h1>';
    settings_errors('pio_oremis_chat_settings');
    echo '<p>Shows the OREMIS Chat bubble at the bottom right of every page. It is served by the chat itself, isolated from the theme.</p>';

    list($healthy, $detail) = pio_oremis_chat_health($settings['url']);
    printf(
        '<p><strong>Chat server:</strong> <span style="color:%s">%s</span> (%s)</p>',
        $healthy ? '#00703c' : '#b32d2e',
        $healthy ? 'reachable' : 'not reachable',
        esc_html($detail)
    );

    echo '<form method="post" action="options.php">';
    settings_fields('pio_oremis_chat');
    echo '<table class="form-table" role="presentation">';

    echo '<tr><th scope="row">Display</th><td><fieldset>';
    foreach ($modes as $value => $label) {
        printf(
            '<label><input type="radio" name="pio_oremis_chat_settings[mode]" value="%s" %s /> %s</label><br />',
            esc_attr($value),
            checked($settings['mode'], $value, false),
            esc_html($label)
        );
    }
    echo '</fieldset></td></tr>';

    echo '<tr><th scope="row"><label for="pio-chat-url">Chat address</label></th><td><input type="url" id="pio-chat-url" class="regular-text" name="pio_oremis_chat_settings[url]" value="' . esc_attr($settings['url']) . '" />';
    echo '<p class="description">The script loaded is <code>' . esc_html(untrailingslashit($settings['url']) . '/embed.js') . '</code>. '
        . 'The chat must list this site in <code>CORS_ORIGINS</code> (' . esc_html(home_url()) . ').</p></td></tr>';

    echo '<tr><th scope="row"><label for="pio-chat-contact">Contact form</label></th><td><input type="url" id="pio-chat-contact" class="regular-text" name="pio_oremis_chat_settings[contact_url]" value="' . esc_attr($settings['contact_url']) . '" />';
    echo '<p class="description">Shown by the bubble if the chat server cannot be reached.</p></td></tr>';

    echo '<tr><th scope="row"><label for="pio-chat-hidden">Hide on these pages</label></th><td><textarea id="pio-chat-hidden" class="regular-text" rows="3" name="pio_oremis_chat_settings[hidden_pages]">' . esc_textarea($settings['hidden_pages']) . '</textarea>';
    echo '<p class="description">Page slugs, one per line (e.g. <code>mentions-legales</code>).</p></td></tr>';

    echo '</table>';
    submit_button();
    echo '</form>';

    echo '<hr /><h2>Shortcode</h2>';
    echo '<p><code>[oremis_chat_link]</code> or <code>[oremis_chat_link text="Parler à un bénévole"]</code>: a link to the full chat page, '
        . 'for example on "Signaler une situation de harcèlement". Shown only when the bubble is.</p>';
    echo '</div>';
}
