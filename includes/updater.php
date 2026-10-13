<?php

/**
 * Updates from the GitHub releases of AssociationOREMIS/pio-wordpress, in Dashboard > Updates like any plugin.
 *
 * The "Update URI" header makes WordPress ask the update_plugins_github.com filter instead of wordpress.org.
 * Each release carries a pio-oremis.zip built by .github/workflows/release.yml.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PIO_OREMIS_GITHUB_REPO', 'AssociationOREMIS/pio-wordpress');
define('PIO_OREMIS_RELEASE_ASSET', 'pio-oremis.zip');
define('PIO_OREMIS_RELEASE_CACHE', 'pio_oremis_latest_release');

/**
 * Latest published release that has its zip: array(version, package, url), or null.
 */
function pio_oremis_latest_release()
{
    // "Check again" in Dashboard > Updates skips the cache.
    $force = is_admin() && !empty($_GET['force-check']);
    $cached = $force ? false : get_site_transient(PIO_OREMIS_RELEASE_CACHE);
    if ($cached !== false) {
        return is_array($cached) ? $cached : null;
    }

    $release = null;
    $response = wp_remote_get('https://api.github.com/repos/' . PIO_OREMIS_GITHUB_REPO . '/releases/latest', array(
        'timeout' => 10,
        'headers' => array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'pio-oremis/' . PIO_OREMIS_VERSION,
        ),
    ));
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $assets = isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : array();
        foreach ($assets as $asset) {
            if (isset($asset['name'], $asset['browser_download_url']) && $asset['name'] === PIO_OREMIS_RELEASE_ASSET && !empty($data['tag_name'])) {
                $release = array(
                    'version' => ltrim((string) $data['tag_name'], 'vV'),
                    'package' => (string) $asset['browser_download_url'],
                    'url' => isset($data['html_url']) ? (string) $data['html_url'] : 'https://github.com/' . PIO_OREMIS_GITHUB_REPO . '/releases',
                );
                break;
            }
        }
    }

    // A failure or a release whose zip is still being built is retried sooner.
    set_site_transient(PIO_OREMIS_RELEASE_CACHE, $release ? $release : 'none', $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
    return $release;
}

function pio_oremis_check_update($update, $plugin_data, $plugin_file)
{
    if ($plugin_file !== plugin_basename(PIO_OREMIS_PLUGIN_FILE)) {
        return $update;
    }
    $release = pio_oremis_latest_release();
    if (!$release) {
        return $update;
    }
    // WordPress only offers it when this version is newer than the installed one.
    return array(
        'slug' => dirname($plugin_file),
        'version' => $release['version'],
        'url' => $release['url'],
        'package' => $release['package'],
    );
}
add_filter('update_plugins_github.com', 'pio_oremis_check_update', 10, 3);

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
