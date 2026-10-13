<?php

/**
 * Updates from the GitHub releases of AssociationOREMIS/pio-wordpress, in Dashboard > Updates like any plugin.
 *
 * Each release carries a pio-oremis.zip built by .github/workflows/release.yml.
 * The update is added to WordPress' list of plugin updates whenever that list is read, so it shows up even
 * when the server cannot reach wordpress.org (WordPress then skips its "Update URI" filters altogether).
 * The "Update URI" header still keeps wordpress.org from offering another plugin with the same folder name.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PIO_OREMIS_GITHUB_REPO', 'AssociationOREMIS/pio-wordpress');
define('PIO_OREMIS_RELEASE_ASSET', 'pio-oremis.zip');
define('PIO_OREMIS_RELEASE_CACHE', 'pio_oremis_latest_release');
define('PIO_OREMIS_UPDATE_STATUS', 'pio_oremis_update_status');

/**
 * Latest release from the github.com redirect, which is not subject to the API's 60 calls per hour per IP.
 */
function pio_oremis_release_from_redirect()
{
    $response = wp_remote_head('https://github.com/' . PIO_OREMIS_GITHUB_REPO . '/releases/latest', array(
        'timeout' => 10,
        'redirection' => 0,
        'headers' => array('User-Agent' => 'pio-oremis/' . PIO_OREMIS_VERSION),
    ));
    if (is_wp_error($response)) {
        return $response;
    }
    $location = (string) wp_remote_retrieve_header($response, 'location');
    if (!preg_match('~/releases/tag/([^/?#]+)$~', $location, $matches)) {
        return new WP_Error('pio_oremis_release_redirect', 'github.com: HTTP ' . wp_remote_retrieve_response_code($response) . ', no release found');
    }
    $tag = rawurldecode($matches[1]);
    return array(
        'version' => ltrim($tag, 'vV'),
        'package' => 'https://github.com/' . PIO_OREMIS_GITHUB_REPO . '/releases/download/' . rawurlencode($tag) . '/' . PIO_OREMIS_RELEASE_ASSET,
        'url' => $location,
    );
}

function pio_oremis_release_from_api()
{
    $response = wp_remote_get('https://api.github.com/repos/' . PIO_OREMIS_GITHUB_REPO . '/releases/latest', array(
        'timeout' => 10,
        'headers' => array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'pio-oremis/' . PIO_OREMIS_VERSION,
        ),
    ));
    if (is_wp_error($response)) {
        return $response;
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return new WP_Error('pio_oremis_release_api', 'api.github.com: HTTP ' . $code);
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $assets = isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : array();
    foreach ($assets as $asset) {
        if (isset($asset['name'], $asset['browser_download_url']) && $asset['name'] === PIO_OREMIS_RELEASE_ASSET && !empty($data['tag_name'])) {
            return array(
                'version' => ltrim((string) $data['tag_name'], 'vV'),
                'package' => (string) $asset['browser_download_url'],
                'url' => isset($data['html_url']) ? (string) $data['html_url'] : 'https://github.com/' . PIO_OREMIS_GITHUB_REPO . '/releases',
            );
        }
    }
    return new WP_Error('pio_oremis_release_api', 'api.github.com: latest release has no ' . PIO_OREMIS_RELEASE_ASSET);
}

/**
 * Latest published release: array(version, package, url), or null. Cached 12 hours (1 hour after a failure).
 */
function pio_oremis_latest_release($force = false)
{
    // "Check again" in Dashboard > Updates skips the cache.
    $force = $force || (is_admin() && !empty($_GET['force-check']));
    $cached = $force ? false : get_site_transient(PIO_OREMIS_RELEASE_CACHE);
    if ($cached !== false) {
        return is_array($cached) ? $cached : null;
    }

    $release = pio_oremis_release_from_redirect();
    if (is_wp_error($release)) {
        $first_error = $release->get_error_message();
        $release = pio_oremis_release_from_api();
        if (is_wp_error($release)) {
            $release = new WP_Error('pio_oremis_release', $first_error . ' / ' . $release->get_error_message());
        }
    }

    $ok = !is_wp_error($release);
    update_site_option(PIO_OREMIS_UPDATE_STATUS, array(
        'checked' => time(),
        'version' => $ok ? $release['version'] : '',
        'error' => $ok ? '' : $release->get_error_message(),
    ));
    set_site_transient(PIO_OREMIS_RELEASE_CACHE, $ok ? $release : 'none', $ok ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
    return $ok ? $release : null;
}

/**
 * Adds or removes this plugin in WordPress' list of plugin updates, every time the list is read.
 */
function pio_oremis_inject_update($transient)
{
    // Only where updates are looked at: never slows down the public pages.
    if (!is_admin() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
        return $transient;
    }
    $release = pio_oremis_latest_release();
    if (!$release) {
        return $transient;
    }
    if (!is_object($transient)) {
        $transient = new stdClass();
    }
    foreach (array('response', 'no_update') as $list) {
        if (!isset($transient->$list) || !is_array($transient->$list)) {
            $transient->$list = array();
        }
    }

    $plugin_file = plugin_basename(PIO_OREMIS_PLUGIN_FILE);
    $item = (object) array(
        'id' => 'https://github.com/' . PIO_OREMIS_GITHUB_REPO,
        'slug' => dirname($plugin_file),
        'plugin' => $plugin_file,
        'new_version' => $release['version'],
        'url' => $release['url'],
        'package' => $release['package'],
    );
    if (version_compare($release['version'], PIO_OREMIS_VERSION, '>')) {
        $transient->response[$plugin_file] = $item;
        unset($transient->no_update[$plugin_file]);
    } else {
        $transient->no_update[$plugin_file] = $item;
        unset($transient->response[$plugin_file]);
    }
    return $transient;
}
add_filter('site_transient_update_plugins', 'pio_oremis_inject_update');

/**
 * The zip holds a pio-oremis/ folder: install it in the folder the plugin already uses, whatever its name,
 * instead of next to it (two copies would declare the same functions).
 */
function pio_oremis_keep_plugin_folder($source, $remote_source, $upgrader, $hook_extra = array())
{
    if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(PIO_OREMIS_PLUGIN_FILE)) {
        return $source;
    }
    global $wp_filesystem;

    $wanted = trailingslashit($remote_source) . dirname($hook_extra['plugin']);
    if (untrailingslashit($source) === $wanted) {
        return $source;
    }
    if (!$wp_filesystem || !$wp_filesystem->move($source, $wanted, true)) {
        return new WP_Error('pio_oremis_update_folder', 'Could not prepare the update folder.');
    }
    return trailingslashit($wanted);
}
add_filter('upgrader_source_selection', 'pio_oremis_keep_plugin_folder', 10, 4);

function pio_oremis_clear_release_cache($upgrader, $options)
{
    if (isset($options['type']) && $options['type'] === 'plugin') {
        delete_site_transient(PIO_OREMIS_RELEASE_CACHE);
    }
}
add_action('upgrader_process_complete', 'pio_oremis_clear_release_cache', 10, 2);

// --------------------------------------------------------------------------
// Extensions page: when the last check ran, what it found, and a button to check now
// --------------------------------------------------------------------------

function pio_oremis_update_row_meta($links, $plugin_file)
{
    if ($plugin_file !== plugin_basename(PIO_OREMIS_PLUGIN_FILE) || !current_user_can('update_plugins')) {
        return $links;
    }
    $status = get_site_option(PIO_OREMIS_UPDATE_STATUS);
    if (is_array($status) && !empty($status['checked'])) {
        $when = sprintf('%s ago', human_time_diff($status['checked']));
        $links[] = $status['error'] !== ''
            ? '<span style="color:#b32d2e">Update check failed ' . esc_html($when) . ': ' . esc_html($status['error']) . '</span>'
            : esc_html('Latest release v' . $status['version'] . ', checked ' . $when);
    }
    $url = wp_nonce_url(admin_url('admin-post.php?action=pio_oremis_check_update'), 'pio_oremis_check_update');
    $links[] = '<a href="' . esc_url($url) . '">Check for updates now</a>';
    return $links;
}
add_filter('plugin_row_meta', 'pio_oremis_update_row_meta', 10, 2);

function pio_oremis_check_update_now()
{
    if (!current_user_can('update_plugins')) {
        wp_die('Sorry, you are not allowed to update plugins.', 403);
    }
    check_admin_referer('pio_oremis_check_update');
    pio_oremis_latest_release(true);
    // Also makes WordPress rebuild its own list of updates on the next page.
    delete_site_transient('update_plugins');
    wp_safe_redirect(admin_url('plugins.php'));
    exit;
}
add_action('admin_post_pio_oremis_check_update', 'pio_oremis_check_update_now');
