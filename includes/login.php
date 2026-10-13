<?php

/**
 * Log in to WordPress with the OREMIS SSO (sso.oremis.fr) or with a Google @oremis.fr account.
 *
 * Accounts are never created or given a role here: the Data -> WordPress sync does that.
 * - OREMIS SSO: the account is found by its CIB (the SSO "sub"), stored by the sync in the oremis_cib user meta.
 * - Google: the account is found by its @oremis.fr email, as the former "Google OAuth Login Standalone" plugin did.
 *   Its options (google_oauth_*) and callback URL are kept, so switching plugins needs no reconfiguration.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PIO_OREMIS_CIB_META', 'oremis_cib');
define('PIO_OREMIS_OAUTH_COOKIE', 'pio_oremis_oauth');
define('PIO_OREMIS_GOOGLE_DOMAIN', 'oremis.fr');

function pio_oremis_login_default_settings()
{
    return array(
        'sso_enabled' => '0',
        'sso_issuer' => 'https://sso.oremis.fr',
        'sso_client_id' => '',
        'sso_client_secret' => '',
        'google_enabled' => '1',
    );
}

function pio_oremis_login_get_settings()
{
    $settings = get_option('pio_oremis_login_settings', array());
    if (!is_array($settings)) {
        $settings = array();
    }
    return wp_parse_args($settings, pio_oremis_login_default_settings());
}

function pio_oremis_sso_client_secret()
{
    if (defined('PIO_OREMIS_SSO_CLIENT_SECRET') && PIO_OREMIS_SSO_CLIENT_SECRET !== '') {
        return PIO_OREMIS_SSO_CLIENT_SECRET;
    }
    $settings = pio_oremis_login_get_settings();
    return $settings['sso_client_secret'];
}

function pio_oremis_sso_issuer()
{
    $settings = pio_oremis_login_get_settings();
    return untrailingslashit($settings['sso_issuer']);
}

function pio_oremis_sso_is_ready()
{
    $settings = pio_oremis_login_get_settings();
    return $settings['sso_enabled'] === '1'
        && $settings['sso_client_id'] !== ''
        && pio_oremis_sso_client_secret() !== ''
        && strpos(pio_oremis_sso_issuer(), 'https://') === 0;
}

/**
 * The former standalone plugin still active: it keeps handling Google, ours stays out of its way.
 */
function pio_oremis_legacy_google_plugin_active()
{
    return class_exists('GoogleOAuthLoginStandalone');
}

function pio_oremis_google_is_ready()
{
    $settings = pio_oremis_login_get_settings();
    return !pio_oremis_legacy_google_plugin_active()
        && $settings['google_enabled'] === '1'
        && get_option('google_oauth_client_id') != ''
        && get_option('google_oauth_client_secret') != '';
}

function pio_oremis_sso_redirect_uri()
{
    return admin_url('admin-ajax.php?action=pio_oremis_sso_callback');
}

function pio_oremis_google_redirect_uri()
{
    // Same URL as the former plugin: it is the one registered in the Google Cloud console.
    return admin_url('admin-ajax.php?action=google_oauth_callback');
}

// --------------------------------------------------------------------------
// CIB user meta, written by the sync through the REST API
// --------------------------------------------------------------------------

function pio_oremis_normalize_cib($cib)
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $cib));
}

function pio_oremis_register_cib_meta()
{
    register_meta('user', PIO_OREMIS_CIB_META, array(
        'type' => 'string',
        'single' => true,
        'default' => '',
        'sanitize_callback' => 'pio_oremis_normalize_cib',
        // Only the sync (an administrator) may set it: whoever holds a CIB logs in to this account.
        'auth_callback' => function () {
            return current_user_can('edit_users');
        },
        // Personal data: never in the public "view" context of /wp/v2/users.
        'show_in_rest' => array(
            'schema' => array(
                'type' => 'string',
                'context' => array('edit'),
            ),
        ),
    ));
}
add_action('init', 'pio_oremis_register_cib_meta');

/**
 * The account linked to this CIB, or null when there is none or more than one.
 */
function pio_oremis_find_user_by_cib($cib)
{
    $cib = pio_oremis_normalize_cib($cib);
    if ($cib === '') {
        return null;
    }
    $users = get_users(array(
        'meta_key' => PIO_OREMIS_CIB_META,
        'meta_value' => $cib,
        'number' => 2,
        'count_total' => false,
    ));
    return count($users) === 1 ? $users[0] : null;
}

function pio_oremis_show_cib_on_profile($user)
{
    if (!current_user_can('edit_users')) {
        return;
    }
    $cib = get_user_meta($user->ID, PIO_OREMIS_CIB_META, true);
    echo '<h2>OREMIS</h2><table class="form-table" role="presentation"><tr>';
    echo '<th>CIB</th><td>' . ($cib !== '' ? '<code>' . esc_html($cib) . '</code>' : '<em>Not linked yet</em>');
    echo '<p class="description">Set by the Data sync. Used to log in with the OREMIS SSO.</p></td>';
    echo '</tr></table>';
}
add_action('show_user_profile', 'pio_oremis_show_cib_on_profile');
add_action('edit_user_profile', 'pio_oremis_show_cib_on_profile');

// --------------------------------------------------------------------------
// OAuth state, bound to the browser that started the login
// --------------------------------------------------------------------------

function pio_oremis_base64url($bytes)
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * Stores what the callback needs and returns the state to send to the provider.
 */
function pio_oremis_oauth_begin($provider, $data)
{
    $state = wp_generate_password(40, false, false);
    $data['provider'] = $provider;
    set_transient('pio_oremis_oauth_' . $state, $data, 10 * MINUTE_IN_SECONDS);

    // Without this cookie, someone could send a victim a callback URL that logs them in to the sender's account.
    setcookie(PIO_OREMIS_OAUTH_COOKIE, $state, array(
        'expires' => time() + 10 * MINUTE_IN_SECONDS,
        'path' => COOKIEPATH ? COOKIEPATH : '/',
        'domain' => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ));

    return $state;
}

/**
 * Data stored by pio_oremis_oauth_begin() for the state in the callback URL, usable once, or false.
 */
function pio_oremis_oauth_take_state($provider)
{
    $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
    $cookie = isset($_COOKIE[PIO_OREMIS_OAUTH_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[PIO_OREMIS_OAUTH_COOKIE])) : '';

    setcookie(PIO_OREMIS_OAUTH_COOKIE, '', array(
        'expires' => time() - HOUR_IN_SECONDS,
        'path' => COOKIEPATH ? COOKIEPATH : '/',
        'domain' => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ));

    if ($state === '' || $cookie === '' || !hash_equals($cookie, $state)) {
        return false;
    }

    $key = 'pio_oremis_oauth_' . $state;
    $data = get_transient($key);
    delete_transient($key);

    if (!is_array($data) || !isset($data['provider']) || $data['provider'] !== $provider) {
        return false;
    }
    return $data;
}

function pio_oremis_login_redirect_target()
{
    $redirect_to = isset($_REQUEST['redirect_to']) ? wp_unslash($_REQUEST['redirect_to']) : '';
    return pio_oremis_safe_redirect_target($redirect_to);
}

/**
 * The given address when it stays on this site, the dashboard otherwise.
 * wp_validate_redirect() returns an empty string, not its fallback, for an empty address.
 */
function pio_oremis_safe_redirect_target($redirect_to)
{
    $target = wp_validate_redirect((string) $redirect_to, '');
    return $target !== '' ? $target : admin_url();
}

function pio_oremis_login_fail($code)
{
    wp_safe_redirect(add_query_arg('pio_login_error', $code, wp_login_url()));
    exit;
}

function pio_oremis_login_user($user, $redirect_to)
{
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, false);
    do_action('wp_login', $user->user_login, $user);

    wp_safe_redirect(pio_oremis_safe_redirect_target($redirect_to));
    exit;
}

/**
 * JSON body of a successful response, or WP_Error.
 */
function pio_oremis_oauth_json($response)
{
    if (is_wp_error($response)) {
        return $response;
    }
    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if ($code !== 200 || !is_array($data)) {
        return new WP_Error('pio_oremis_oauth_http', 'Unexpected response code: ' . $code, array('status' => $code));
    }
    return $data;
}

// --------------------------------------------------------------------------
// OREMIS SSO
// --------------------------------------------------------------------------

function pio_oremis_sso_start()
{
    if (!pio_oremis_sso_is_ready()) {
        pio_oremis_login_fail('unavailable');
    }

    $settings = pio_oremis_login_get_settings();
    $verifier = wp_generate_password(64, false, false);
    $state = pio_oremis_oauth_begin('sso', array(
        'verifier' => $verifier,
        'redirect_to' => pio_oremis_login_redirect_target(),
    ));

    // Only "profile": the CIB ("sub") is always sent, nothing else is needed.
    $url = pio_oremis_sso_issuer() . '/oauth/authorize?' . http_build_query(array(
        'client_id' => $settings['sso_client_id'],
        'redirect_uri' => pio_oremis_sso_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'profile',
        'state' => $state,
        'code_challenge' => pio_oremis_base64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
    ), '', '&', PHP_QUERY_RFC3986);

    wp_redirect($url);
    exit;
}
add_action('wp_ajax_nopriv_pio_oremis_sso_start', 'pio_oremis_sso_start');
add_action('wp_ajax_pio_oremis_sso_start', 'pio_oremis_sso_start');

function pio_oremis_sso_callback()
{
    if (!pio_oremis_sso_is_ready()) {
        pio_oremis_login_fail('unavailable');
    }

    $flow = pio_oremis_oauth_take_state('sso');
    if ($flow === false) {
        pio_oremis_login_fail('expired');
    }
    if (isset($_GET['error'])) {
        pio_oremis_login_fail('cancelled');
    }
    $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
    if ($code === '') {
        pio_oremis_login_fail('expired');
    }

    $settings = pio_oremis_login_get_settings();
    $issuer = pio_oremis_sso_issuer();
    $client = array(
        'client_id' => $settings['sso_client_id'],
        'client_secret' => pio_oremis_sso_client_secret(),
    );

    $tokens = pio_oremis_oauth_json(wp_safe_remote_post($issuer . '/oauth/token', array(
        'timeout' => 10,
        'headers' => array('Accept' => 'application/json'),
        'body' => $client + array(
            'grant_type' => 'authorization_code',
            'redirect_uri' => pio_oremis_sso_redirect_uri(),
            'code' => $code,
            'code_verifier' => $flow['verifier'],
        ),
    )));
    if (is_wp_error($tokens) || empty($tokens['access_token'])) {
        pio_oremis_login_fail('provider');
    }

    $response = wp_safe_remote_get($issuer . '/api/userinfo', array(
        'timeout' => 10,
        'headers' => array(
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $tokens['access_token'],
        ),
    ));

    // WordPress keeps its own session: the tokens are not needed any more, so none is left lying around.
    wp_safe_remote_post($issuer . '/oauth/revoke', array(
        'timeout' => 5,
        'headers' => array('Accept' => 'application/json'),
        'body' => $client + array('token' => $tokens['access_token']),
    ));

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 403) {
        pio_oremis_login_fail('refused');
    }
    $profile = pio_oremis_oauth_json($response);
    if (is_wp_error($profile) || empty($profile['sub'])) {
        pio_oremis_login_fail('provider');
    }

    $user = pio_oremis_find_user_by_cib($profile['sub']);
    if (!$user) {
        pio_oremis_login_fail('no_account');
    }

    pio_oremis_login_user($user, $flow['redirect_to']);
}
add_action('wp_ajax_nopriv_pio_oremis_sso_callback', 'pio_oremis_sso_callback');
add_action('wp_ajax_pio_oremis_sso_callback', 'pio_oremis_sso_callback');

// --------------------------------------------------------------------------
// Google (@oremis.fr accounts)
// --------------------------------------------------------------------------

function pio_oremis_google_start()
{
    if (!pio_oremis_google_is_ready()) {
        pio_oremis_login_fail('unavailable');
    }

    $state = pio_oremis_oauth_begin('google', array(
        'redirect_to' => pio_oremis_login_redirect_target(),
    ));

    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(array(
        'client_id' => get_option('google_oauth_client_id'),
        'redirect_uri' => pio_oremis_google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'email profile',
        'access_type' => 'online',
        'prompt' => 'select_account',
        'hd' => PIO_OREMIS_GOOGLE_DOMAIN,
        'state' => $state,
    ));

    wp_redirect($url);
    exit;
}

function pio_oremis_google_callback()
{
    $flow = pio_oremis_oauth_take_state('google');
    if ($flow === false) {
        pio_oremis_login_fail('expired');
    }
    if (isset($_GET['error'])) {
        pio_oremis_login_fail('cancelled');
    }
    $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
    if ($code === '') {
        pio_oremis_login_fail('expired');
    }

    $tokens = pio_oremis_oauth_json(wp_safe_remote_post('https://oauth2.googleapis.com/token', array(
        'timeout' => 10,
        'body' => array(
            'code' => $code,
            'client_id' => get_option('google_oauth_client_id'),
            'client_secret' => get_option('google_oauth_client_secret'),
            'redirect_uri' => pio_oremis_google_redirect_uri(),
            'grant_type' => 'authorization_code',
        ),
    )));
    if (is_wp_error($tokens) || empty($tokens['access_token'])) {
        pio_oremis_login_fail('provider');
    }

    $profile = pio_oremis_oauth_json(wp_safe_remote_get('https://www.googleapis.com/oauth2/v3/userinfo', array(
        'timeout' => 10,
        'headers' => array('Authorization' => 'Bearer ' . $tokens['access_token']),
    )));
    if (is_wp_error($profile) || empty($profile['email'])) {
        pio_oremis_login_fail('provider');
    }
    if (isset($profile['email_verified']) && !in_array($profile['email_verified'], array(true, 'true', 1), true)) {
        pio_oremis_login_fail('provider');
    }

    $email = strtolower(sanitize_email($profile['email']));
    $domain = substr((string) strrchr($email, '@'), 1);
    if ($email === '' || strtolower($domain) !== PIO_OREMIS_GOOGLE_DOMAIN) {
        pio_oremis_login_fail('google_domain');
    }

    $user = get_user_by('email', $email);
    if (!$user) {
        $user = pio_oremis_google_create_user($email, $profile);
    }
    if (!$user) {
        pio_oremis_login_fail('no_account');
    }

    pio_oremis_login_user($user, $flow['redirect_to']);
}

/**
 * Kept from the former plugin, off by default: the Data sync is what creates accounts now.
 */
function pio_oremis_google_create_user($email, $profile)
{
    if (get_option('google_oauth_allow_create', '0') !== '1') {
        return null;
    }

    $base_username = sanitize_user(substr($email, 0, strpos($email, '@')), true);
    if ($base_username === '') {
        $base_username = 'user';
    }
    $username = $base_username;
    $counter = 1;
    while (username_exists($username)) {
        $username = $base_username . $counter;
        $counter++;
    }

    $user_id = wp_create_user($username, wp_generate_password(), $email);
    if (is_wp_error($user_id)) {
        return null;
    }
    wp_update_user(array(
        'ID' => $user_id,
        'display_name' => isset($profile['name']) ? sanitize_text_field($profile['name']) : '',
        'first_name' => isset($profile['given_name']) ? sanitize_text_field($profile['given_name']) : '',
        'last_name' => isset($profile['family_name']) ? sanitize_text_field($profile['family_name']) : '',
    ));
    return get_user_by('id', $user_id);
}

function pio_oremis_register_google_login()
{
    // While the former plugin is active it answers on the same callback URL: let it.
    if (pio_oremis_legacy_google_plugin_active()) {
        return;
    }
    add_action('wp_ajax_nopriv_pio_oremis_google_start', 'pio_oremis_google_start');
    add_action('wp_ajax_pio_oremis_google_start', 'pio_oremis_google_start');
    add_action('wp_ajax_nopriv_google_oauth_callback', 'pio_oremis_google_callback');
    add_action('wp_ajax_google_oauth_callback', 'pio_oremis_google_callback');
}
add_action('plugins_loaded', 'pio_oremis_register_google_login');

// --------------------------------------------------------------------------
// Login page
// --------------------------------------------------------------------------

function pio_oremis_login_buttons()
{
    $sso = pio_oremis_sso_is_ready();
    $google = pio_oremis_google_is_ready();
    if (!$sso && !$google) {
        return;
    }

    $args = array();
    if (!empty($_REQUEST['redirect_to'])) {
        $args['redirect_to'] = rawurlencode(wp_unslash($_REQUEST['redirect_to']));
    }

    echo '<div class="pio-login-providers">';
    if ($sso) {
        $url = add_query_arg($args + array('action' => 'pio_oremis_sso_start'), admin_url('admin-ajax.php'));
        echo '<a class="pio-login-button pio-login-button--oremis" href="' . esc_url($url) . '">Se connecter avec mon compte OREMIS</a>';
    }
    if ($google) {
        $url = add_query_arg($args + array('action' => 'pio_oremis_google_start'), admin_url('admin-ajax.php'));
        echo '<a class="pio-login-button pio-login-button--google" href="' . esc_url($url) . '">';
        echo '<img src="' . esc_url(PIO_OREMIS_PLUGIN_URL . 'assets/images/google-icon.svg') . '" alt="" width="18" height="18" />';
        echo 'Se connecter avec Google (@oremis.fr)</a>';
    }
    echo '</div>';
}
add_action('login_form', 'pio_oremis_login_buttons');

function pio_oremis_login_styles()
{
    wp_enqueue_style('pio-oremis-login', PIO_OREMIS_PLUGIN_URL . 'assets/login.css', array(), PIO_OREMIS_VERSION);
}
add_action('login_enqueue_scripts', 'pio_oremis_login_styles');

function pio_oremis_login_error_message($errors)
{
    if (empty($_GET['pio_login_error'])) {
        return $errors;
    }

    $messages = array(
        'unavailable' => 'Cette méthode de connexion n’est pas disponible pour le moment.',
        'expired' => 'La connexion a expiré ou a été commencée dans un autre navigateur. Merci de réessayer.',
        'cancelled' => 'La connexion a été annulée.',
        'provider' => 'Le service de connexion ne répond pas comme prévu. Merci de réessayer dans quelques minutes.',
        'refused' => 'Votre compte OREMIS ne peut pas se connecter pour le moment (compte inactif ou suspendu). Contactez votre responsable.',
        'no_account' => 'Votre compte OREMIS n’a pas accès à ce site. Si vous pensez que c’est une erreur, contactez l’équipe web.',
        'google_domain' => 'Seuls les comptes Google @oremis.fr peuvent se connecter ici.',
    );
    $code = sanitize_key(wp_unslash($_GET['pio_login_error']));
    if (isset($messages[$code])) {
        $errors->add('pio_oremis_' . $code, esc_html($messages[$code]));
    }
    return $errors;
}
add_filter('wp_login_errors', 'pio_oremis_login_error_message');

// --------------------------------------------------------------------------
// Settings: OREMIS > Connexion
// --------------------------------------------------------------------------

function pio_oremis_login_admin_menu()
{
    add_submenu_page(
        'pio-oremis',
        'Connexion',
        'Connexion',
        pio_oremis_settings_capability(),
        'pio-oremis-login',
        'pio_oremis_render_login_settings_page'
    );
}
// After pio_oremis_admin_menu(), which creates the parent OREMIS menu.
add_action('admin_menu', 'pio_oremis_login_admin_menu', 20);

function pio_oremis_keep_secret_if_empty($value, $previous)
{
    $value = sanitize_text_field((string) $value);
    return $value !== '' ? $value : (string) $previous;
}

function pio_oremis_sanitize_login_settings($input)
{
    $input = is_array($input) ? $input : array();
    $previous = pio_oremis_login_get_settings();
    $defaults = pio_oremis_login_default_settings();

    $issuer = isset($input['sso_issuer']) ? esc_url_raw(trim($input['sso_issuer'])) : '';
    return array(
        'sso_enabled' => empty($input['sso_enabled']) ? '0' : '1',
        'sso_issuer' => $issuer !== '' ? untrailingslashit($issuer) : $defaults['sso_issuer'],
        'sso_client_id' => isset($input['sso_client_id']) ? sanitize_text_field($input['sso_client_id']) : '',
        'sso_client_secret' => pio_oremis_keep_secret_if_empty(isset($input['sso_client_secret']) ? $input['sso_client_secret'] : '', $previous['sso_client_secret']),
        'google_enabled' => empty($input['google_enabled']) ? '0' : '1',
    );
}

function pio_oremis_login_admin_init()
{
    register_setting('pio_oremis_login', 'pio_oremis_login_settings', array(
        'sanitize_callback' => 'pio_oremis_sanitize_login_settings',
    ));
    // Options of the former Google plugin, kept under their names.
    register_setting('pio_oremis_login', 'google_oauth_client_id', array(
        'sanitize_callback' => 'sanitize_text_field',
    ));
    register_setting('pio_oremis_login', 'google_oauth_client_secret', array(
        'sanitize_callback' => function ($value) {
            return pio_oremis_keep_secret_if_empty($value, get_option('google_oauth_client_secret', ''));
        },
    ));
    register_setting('pio_oremis_login', 'google_oauth_allow_create', array(
        'sanitize_callback' => function ($value) {
            return $value === '1' ? '1' : '0';
        },
    ));
}
add_action('admin_init', 'pio_oremis_login_admin_init');

function pio_oremis_legacy_google_notice()
{
    if (!pio_oremis_legacy_google_plugin_active() || !current_user_can(pio_oremis_settings_capability())) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, array('plugins', 'oremis_page_pio-oremis-login'), true)) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>Google OAuth Login Standalone</strong> is still active, so it keeps handling Google login. '
        . 'The OREMIS plugin now does the same with the same settings and the same callback URL: you can deactivate it, nothing to reconfigure.</p></div>';
}
add_action('admin_notices', 'pio_oremis_legacy_google_notice');

function pio_oremis_render_login_settings_page()
{
    if (!current_user_can(pio_oremis_settings_capability())) {
        return;
    }
    $settings = pio_oremis_login_get_settings();
    $secret_from_config = defined('PIO_OREMIS_SSO_CLIENT_SECRET') && PIO_OREMIS_SSO_CLIENT_SECRET !== '';
    $linked = count(get_users(array(
        'meta_key' => PIO_OREMIS_CIB_META,
        'meta_compare' => '!=',
        'meta_value' => '',
        'fields' => 'ID',
    )));
    $saved = '••••••••';

    echo '<div class="wrap"><h1>Connexion</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('pio_oremis_login');

    echo '<h2>OREMIS SSO</h2>';
    echo '<p>Accounts are matched by CIB, set by the Data sync. ' . esc_html(sprintf('%d account(s) linked so far.', $linked)) . '</p>';
    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row">Enable</th><td><label><input type="checkbox" name="pio_oremis_login_settings[sso_enabled]" value="1" ' . checked($settings['sso_enabled'], '1', false) . ' /> Show "Se connecter avec mon compte OREMIS" on the login page</label></td></tr>';
    echo '<tr><th scope="row"><label for="pio-sso-issuer">Issuer</label></th><td><input type="url" id="pio-sso-issuer" class="regular-text" name="pio_oremis_login_settings[sso_issuer]" value="' . esc_attr($settings['sso_issuer']) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="pio-sso-client-id">Client ID</label></th><td><input type="text" id="pio-sso-client-id" class="regular-text" name="pio_oremis_login_settings[sso_client_id]" value="' . esc_attr($settings['sso_client_id']) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="pio-sso-client-secret">Client secret</label></th><td>';
    if ($secret_from_config) {
        echo '<input type="password" id="pio-sso-client-secret" class="regular-text" placeholder="' . esc_attr($saved) . '" disabled /><p class="description">Set in wp-config.php (PIO_OREMIS_SSO_CLIENT_SECRET).</p>';
    } else {
        echo '<input type="password" id="pio-sso-client-secret" class="regular-text" name="pio_oremis_login_settings[sso_client_secret]" value="" placeholder="' . esc_attr($settings['sso_client_secret'] !== '' ? $saved : '') . '" autocomplete="new-password" />';
        echo '<p class="description">Never shown again once saved: leave empty to keep it. Can also be set in wp-config.php (PIO_OREMIS_SSO_CLIENT_SECRET).</p>';
    }
    echo '</td></tr>';
    echo '<tr><th scope="row">Redirect URI</th><td><code>' . esc_html(pio_oremis_sso_redirect_uri()) . '</code><p class="description">To register on the SSO client in Data (Clients SSO). Confidential client, OpenID Connect not needed.</p></td></tr>';
    echo '</table>';

    echo '<h2>Google (@oremis.fr)</h2>';
    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row">Enable</th><td><label><input type="checkbox" name="pio_oremis_login_settings[google_enabled]" value="1" ' . checked($settings['google_enabled'], '1', false) . ' /> Show "Se connecter avec Google" on the login page</label></td></tr>';
    echo '<tr><th scope="row"><label for="pio-google-client-id">Client ID</label></th><td><input type="text" id="pio-google-client-id" class="regular-text" name="google_oauth_client_id" value="' . esc_attr(get_option('google_oauth_client_id', '')) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="pio-google-client-secret">Client secret</label></th><td><input type="password" id="pio-google-client-secret" class="regular-text" name="google_oauth_client_secret" value="" placeholder="' . esc_attr(get_option('google_oauth_client_secret', '') != '' ? $saved : '') . '" autocomplete="new-password" /><p class="description">Never shown again once saved: leave empty to keep it.</p></td></tr>';
    echo '<tr><th scope="row">Account creation</th><td><label><input type="checkbox" name="google_oauth_allow_create" value="1" ' . checked(get_option('google_oauth_allow_create', '0'), '1', false) . ' /> Create a WordPress account for an unknown @oremis.fr address</label><p class="description">Leave off: accounts are created by the Data sync.</p></td></tr>';
    echo '<tr><th scope="row">Redirect URI</th><td><code>' . esc_html(pio_oremis_google_redirect_uri()) . '</code><p class="description">Same as the former plugin, already registered in the Google Cloud console.</p></td></tr>';
    echo '</table>';

    submit_button();
    echo '</form></div>';
}
