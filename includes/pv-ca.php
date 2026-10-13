<?php

if (!defined('ABSPATH')) {
    exit;
}

function pio_oremis_register_pv_post_types()
{
    pio_oremis_register_single_pv_post_type('pv_ca', array(
        'name' => 'PV du CA',
        'singular_name' => 'Séance du CA',
        'menu_name' => 'PV du CA',
        'name_admin_bar' => 'Séance du CA',
        'add_new' => 'Ajouter',
        'add_new_item' => 'Ajouter une séance du CA',
        'edit_item' => 'Modifier la séance du CA',
        'new_item' => 'Nouvelle séance du CA',
        'view_item' => 'Voir la séance du CA',
        'search_items' => 'Rechercher une séance',
        'not_found' => 'Aucune séance trouvée.',
        'not_found_in_trash' => 'Aucune séance dans la corbeille.',
        'all_items' => 'Gestion PV CA',
    ));

    pio_oremis_register_single_pv_post_type('pv_ag', array(
        'name' => 'PV de l’AG',
        'singular_name' => 'Séance de l’AG',
        'menu_name' => 'PV de l’AG',
        'name_admin_bar' => 'Séance de l’AG',
        'add_new' => 'Ajouter',
        'add_new_item' => 'Ajouter une séance de l’AG',
        'edit_item' => 'Modifier la séance de l’AG',
        'new_item' => 'Nouvelle séance de l’AG',
        'view_item' => 'Voir la séance de l’AG',
        'search_items' => 'Rechercher une séance',
        'not_found' => 'Aucune séance trouvée.',
        'not_found_in_trash' => 'Aucune séance dans la corbeille.',
        'all_items' => 'Gestion PV AG',
    ));
}
add_action('init', 'pio_oremis_register_pv_post_types');

function pio_oremis_register_single_pv_post_type($post_type, $labels)
{
    register_post_type($post_type, array(
        'labels' => $labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'pio-oremis',
        'show_in_admin_bar' => false,
        'show_in_nav_menus' => false,
        'exclude_from_search' => true,
        'publicly_queryable' => false,
        'has_archive' => false,
        'rewrite' => false,
        'menu_icon' => 'dashicons-media-document',
        'supports' => array('title'),
        'capability_type' => 'post',
        'map_meta_cap' => true,
    ));
}

function pio_oremis_add_pv_meta_boxes()
{
    add_meta_box(
        'pio_oremis_pv_ca_details',
        'Détails de la séance',
        'pio_oremis_render_pv_meta_box',
        'pv_ca',
        'normal',
        'high'
    );

    add_meta_box(
        'pio_oremis_pv_ag_details',
        'Détails de la séance',
        'pio_oremis_render_pv_meta_box',
        'pv_ag',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'pio_oremis_add_pv_meta_boxes');

function pio_oremis_render_pv_meta_box($post)
{
    $session_date = get_post_meta($post->ID, 'session_date', true);
    $pv_file_url = get_post_meta($post->ID, 'pv_file_url', true);
    $deliberations_file_url = get_post_meta($post->ID, 'deliberations_file_url', true);

    $post_type = $post->post_type === 'pv_ag' ? 'pv_ag' : 'pv_ca';
    $nonce_name = $post_type === 'pv_ag' ? 'pio_oremis_pv_ag_nonce' : 'pio_oremis_pv_ca_nonce';
    $nonce_action = $post_type === 'pv_ag' ? 'pio_oremis_save_pv_ag_meta' : 'pio_oremis_save_pv_ca_meta';

    wp_nonce_field($nonce_action, $nonce_name);

    echo '<p><label for="pio_oremis_session_date"><strong>Date de la séance</strong></label><br />';
    echo '<input type="date" id="pio_oremis_session_date" name="pio_oremis_session_date" value="' . esc_attr($session_date) . '" /></p>';

    echo '<p><label for="pio_oremis_pv_file_url"><strong>Fichier PDF du procès-verbal</strong></label><br />';
    echo '<input type="url" class="regular-text" id="pio_oremis_pv_file_url" name="pio_oremis_pv_file_url" value="' . esc_attr($pv_file_url) . '" />';
    echo ' <button type="button" class="button pio-pv-upload" data-target="pio_oremis_pv_file_url">Choisir un fichier</button>';
    echo ' <button type="button" class="button-link-delete pio-pv-clear" data-target="pio_oremis_pv_file_url">Effacer</button></p>';

    echo '<p><label for="pio_oremis_deliberations_file_url"><strong>Liste des délibérations examinées (optionnel)</strong></label><br />';
    echo '<input type="url" class="regular-text" id="pio_oremis_deliberations_file_url" name="pio_oremis_deliberations_file_url" value="' . esc_attr($deliberations_file_url) . '" />';
    echo ' <button type="button" class="button pio-pv-upload" data-target="pio_oremis_deliberations_file_url">Choisir un fichier</button>';
    echo ' <button type="button" class="button-link-delete pio-pv-clear" data-target="pio_oremis_deliberations_file_url">Effacer</button></p>';
}

function pio_oremis_enqueue_pv_admin_assets($hook_suffix)
{
    if ($hook_suffix !== 'post.php' && $hook_suffix !== 'post-new.php') {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, array('pv_ca', 'pv_ag'), true)) {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_script('jquery');

    $script = <<<'JS'
jQuery(function ($) {
    function attachMediaPicker(button) {
        var targetId = button.data('target');
        if (!targetId || typeof wp === 'undefined' || !wp.media) {
            return;
        }

        var frame = wp.media({
            title: 'Choisir un fichier',
            button: { text: 'Utiliser ce fichier' },
            multiple: false
        });

        frame.on('select', function () {
            var selection = frame.state().get('selection').first();
            if (!selection) {
                return;
            }
            var attachment = selection.toJSON();
            $('#' + targetId).val(attachment.url).trigger('change');
        });

        frame.open();
    }

    $(document).on('click', '.pio-pv-upload', function (event) {
        event.preventDefault();
        attachMediaPicker($(this));
    });

    $(document).on('click', '.pio-pv-clear', function (event) {
        event.preventDefault();
        var targetId = $(this).data('target');
        if (!targetId) {
            return;
        }
        $('#' + targetId).val('').trigger('change');
    });
});
JS;

    wp_add_inline_script('jquery', $script, 'after');
}
add_action('admin_enqueue_scripts', 'pio_oremis_enqueue_pv_admin_assets');

function pio_oremis_show_pv_shortcode_help_notice()
{
    if (!is_admin()) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, array('pv_ca', 'pv_ag'), true)) {
        return;
    }

    if (!in_array($screen->base, array('edit', 'post'), true)) {
        return;
    }

    $is_ag = $screen->post_type === 'pv_ag';
    $shortcode_tag = $is_ag ? 'pv_ag_list' : 'pv_ca_list';
    $shortcode = '[' . $shortcode_tag . ']';
    $shortcode_posts_per_page = '[' . $shortcode_tag . ' posts_per_page="20"]';
    $shortcode_without_pagination = '[' . $shortcode_tag . ' show_pagination="0"]';
    $shortcode_with_default_pagination = '[' . $shortcode_tag . ' posts_per_page="10" show_pagination="1"]';
    $label = $is_ag ? 'PV de l’AG' : 'PV du CA';

    echo '<div class="notice notice-info">';
    echo '<p><strong>Affichage public ' . esc_html($label) . ' :</strong> créez/éditez une page WordPress puis collez le shortcode <code>' . esc_html($shortcode) . '</code>.</p>';
    echo '<p>Options disponibles: <code>' . esc_html($shortcode) . '</code>, <code>' . esc_html($shortcode_posts_per_page) . '</code>, <code>' . esc_html($shortcode_without_pagination) . '</code> (désactive la pagination numérotée), <code>' . esc_html($shortcode_with_default_pagination) . '</code>.</p>';
    echo '</div>';
}
add_action('admin_notices', 'pio_oremis_show_pv_shortcode_help_notice');

function pio_oremis_save_pv_ca_meta($post_id)
{
    pio_oremis_save_pv_meta($post_id, 'pio_oremis_pv_ca_nonce', 'pio_oremis_save_pv_ca_meta');
}
add_action('save_post_pv_ca', 'pio_oremis_save_pv_ca_meta');

function pio_oremis_save_pv_ag_meta($post_id)
{
    pio_oremis_save_pv_meta($post_id, 'pio_oremis_pv_ag_nonce', 'pio_oremis_save_pv_ag_meta');
}
add_action('save_post_pv_ag', 'pio_oremis_save_pv_ag_meta');

function pio_oremis_save_pv_meta($post_id, $nonce_name, $nonce_action)
{
    if (!isset($_POST[$nonce_name])) {
        return;
    }

    $nonce = sanitize_text_field(wp_unslash($_POST[$nonce_name]));
    if (!wp_verify_nonce($nonce, $nonce_action)) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $session_date = '';
    if (isset($_POST['pio_oremis_session_date'])) {
        $session_date = sanitize_text_field(wp_unslash($_POST['pio_oremis_session_date']));
    }

    if ($session_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $session_date) === 1) {
        update_post_meta($post_id, 'session_date', $session_date);
    } else {
        delete_post_meta($post_id, 'session_date');
    }

    $meta_map = array(
        'pio_oremis_pv_file_url' => 'pv_file_url',
        'pio_oremis_deliberations_file_url' => 'deliberations_file_url',
    );

    foreach ($meta_map as $posted_key => $meta_key) {
        $value = '';
        if (isset($_POST[$posted_key])) {
            $value = esc_url_raw(trim(wp_unslash($_POST[$posted_key])));
        }

        if ($value !== '') {
            update_post_meta($post_id, $meta_key, $value);
        } else {
            delete_post_meta($post_id, $meta_key);
        }
    }
}

function pio_oremis_format_session_date($session_date)
{
    $session_date = trim((string) $session_date);
    if ($session_date === '') {
        return '';
    }

    $timezone = wp_timezone();
    $date = date_create_immutable_from_format('!Y-m-d', $session_date, $timezone);
    if (!$date || $date->format('Y-m-d') !== $session_date) {
        return '';
    }

    return wp_date('j F Y', $date->getTimestamp(), $timezone);
}

/**
 * Heading tag for list items, from the heading_level shortcode attribute (h2 to h6, h3 by default).
 */
function pio_oremis_list_heading_tag($heading_level)
{
    $level = intval(preg_replace('/\D/', '', (string) $heading_level));
    return 'h' . ($level >= 2 && $level <= 6 ? $level : 3);
}

/**
 * Number of items per page from the posts_per_page shortcode attribute, between 1 and 100.
 */
function pio_oremis_list_posts_per_page($posts_per_page)
{
    return max(1, min(100, intval($posts_per_page)));
}

/**
 * Download link opening a new tab, announced to screen readers.
 */
function pio_oremis_list_download_link($url, $label)
{
    return '<a class="pio-pv-ca-button" href="' . esc_url($url) . '" target="_blank" rel="noopener">'
        . esc_html($label)
        . '<span class="pio-sr-only"> (s’ouvre dans un nouvel onglet)</span></a>';
}

function pio_oremis_render_pv_list_shortcode($atts, $shortcode_tag, $post_type, $page_query_arg, $empty_message, $default_title, $pagination_label)
{
    $atts = shortcode_atts(array(
        'posts_per_page' => '10',
        'show_pagination' => '1',
        'heading_level' => '3',
    ), $atts, $shortcode_tag);

    $posts_per_page = pio_oremis_list_posts_per_page($atts['posts_per_page']);
    $heading_tag = pio_oremis_list_heading_tag($atts['heading_level']);
    $show_pagination = in_array(strtolower((string) $atts['show_pagination']), array('1', 'true', 'yes'), true);

    $current_page = 1;
    if (isset($_GET[$page_query_arg])) {
        $current_page = max(1, absint(wp_unslash($_GET[$page_query_arg])));
    }

    $pv_query = new WP_Query(array(
        'post_type' => $post_type,
        'post_status' => 'publish',
        'posts_per_page' => $posts_per_page,
        'paged' => $current_page,
        // Sessions without a date stay listed, after the dated ones.
        'meta_query' => array(
            'relation' => 'OR',
            'session_date_clause' => array(
                'key' => 'session_date',
                'compare' => 'EXISTS',
                'type' => 'DATE',
            ),
            array(
                'key' => 'session_date',
                'compare' => 'NOT EXISTS',
            ),
        ),
        'orderby' => array(
            'session_date_clause' => 'DESC',
            'date' => 'DESC',
        ),
        'ignore_sticky_posts' => true,
        'no_found_rows' => !$show_pagination,
    ));

    if (!$pv_query->have_posts()) {
        return '<div class="pio-pv-ca-empty">' . esc_html($empty_message) . '</div>';
    }

    wp_enqueue_style('pio-oremis');

    $output = '<div class="pio-pv-ca-list">';

    while ($pv_query->have_posts()) {
        $pv_query->the_post();
        $post_id = get_the_ID();

        $session_date = pio_oremis_format_session_date(get_post_meta($post_id, 'session_date', true));
        if ($session_date === '') {
            $session_date = 'Date non renseignée';
        }

        $title = trim((string) get_post_field('post_title', $post_id));
        if ($title === '') {
            $title = $default_title;
        }

        $pv_file_url = esc_url(get_post_meta($post_id, 'pv_file_url', true));
        $deliberations_file_url = esc_url(get_post_meta($post_id, 'deliberations_file_url', true));

        $output .= '<article class="pio-pv-ca-item">';
        $output .= '<' . $heading_tag . ' class="pio-pv-ca-heading">';
        $output .= '<span class="pio-pv-ca-date">' . esc_html($session_date) . '</span>';
        $output .= '<span class="pio-pv-ca-title">' . esc_html($title) . '</span>';
        $output .= '</' . $heading_tag . '>';

        if ($pv_file_url !== '' || $deliberations_file_url !== '') {
            $output .= '<div class="pio-pv-ca-actions">';
            if ($pv_file_url !== '') {
                $output .= pio_oremis_list_download_link($pv_file_url, 'Télécharger le PV');
            }
            if ($deliberations_file_url !== '') {
                $output .= pio_oremis_list_download_link($deliberations_file_url, 'Télécharger la liste des délibérations examinées');
            }
            $output .= '</div>';
        }

        $output .= '</article>';
    }

    $output .= '</div>';

    if ($show_pagination && $pv_query->max_num_pages > 1) {
        $base_url = remove_query_arg(array($page_query_arg));
        $pagination = paginate_links(array(
            'base' => esc_url_raw(add_query_arg($page_query_arg, '%#%', $base_url)),
            'format' => '',
            'current' => $current_page,
            'total' => intval($pv_query->max_num_pages),
            'type' => 'list',
            'prev_text' => 'Précédent',
            'next_text' => 'Suivant',
        ));

        if ($pagination) {
            $output .= '<nav class="pio-pv-ca-pagination" aria-label="' . esc_attr($pagination_label) . '">' . $pagination . '</nav>';
        }
    }

    wp_reset_postdata();

    return $output;
}

function pio_oremis_shortcode_pv_ca_list($atts)
{
    return pio_oremis_render_pv_list_shortcode(
        $atts,
        'pv_ca_list',
        'pv_ca',
        'pv_ca_page',
        'Aucun procès-verbal disponible pour le moment.',
        'Séance du conseil d’administration',
        'Pagination des procès-verbaux du CA'
    );
}
add_shortcode('pv_ca_list', 'pio_oremis_shortcode_pv_ca_list');

function pio_oremis_shortcode_pv_ag_list($atts)
{
    return pio_oremis_render_pv_list_shortcode(
        $atts,
        'pv_ag_list',
        'pv_ag',
        'pv_ag_page',
        'Aucun procès-verbal d’assemblée générale disponible pour le moment.',
        'Séance de l’assemblée générale',
        'Pagination des procès-verbaux de l’assemblée générale'
    );
}
add_shortcode('pv_ag_list', 'pio_oremis_shortcode_pv_ag_list');
