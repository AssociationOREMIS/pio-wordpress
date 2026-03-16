<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capability used to access and edit this plugin settings.
 */
function pio_oremis_settings_capability()
{
    return 'pio_oremis_manage_settings';
}

/**
 * Add/refresh plugin capabilities for administrator and webmaster roles.
 */
function pio_oremis_register_roles_and_caps()
{
    $settings_cap = pio_oremis_settings_capability();

    $webmaster_caps = array(
        'read' => true,
        'upload_files' => true,
        'edit_posts' => true,
        'edit_pages' => true,
        'edit_published_posts' => true,
        'edit_published_pages' => true,
        'publish_posts' => true,
        'publish_pages' => true,
        'edit_others_posts' => true,
        'edit_others_pages' => true,
        'delete_posts' => true,
        'delete_pages' => true,
        'delete_published_posts' => true,
        'delete_published_pages' => true,
        // Basic site management without full admin settings access.
        'manage_categories' => true,
        'moderate_comments' => true,
        'edit_theme_options' => true,
        'view_site_health_checks' => true,
        'activate_plugins' => true,
        'update_plugins' => true,
        'update_themes' => true,
        'switch_themes' => false,
        // Plugin settings access without full admin rights.
        $settings_cap => true,
    );

    add_role('webmaster', 'Webmaster', $webmaster_caps);

    $webmaster_role = get_role('webmaster');
    if ($webmaster_role instanceof WP_Role) {
        foreach ($webmaster_caps as $capability => $grant) {
            if ($grant) {
                $webmaster_role->add_cap($capability);
            } else {
                $webmaster_role->remove_cap($capability);
            }
        }
        // Keep install/delete plugin capabilities restricted to admins.
        $webmaster_role->remove_cap('install_plugins');
        $webmaster_role->remove_cap('delete_plugins');
    }

    // Keep administrators compatible with the custom plugin capability.
    $admin_role = get_role('administrator');
    if ($admin_role instanceof WP_Role) {
        $admin_role->add_cap($settings_cap);
    }
}
