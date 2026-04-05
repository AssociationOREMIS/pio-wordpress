<?php

if (!defined('ABSPATH')) {
    exit;
}

function pio_oremis_register_upcoming_post_types()
{
    pio_oremis_register_single_upcoming_post_type('upcoming_ca', array(
        'name' => 'Séances CA à venir',
        'singular_name' => 'Séance CA à venir',
        'menu_name' => 'Séances CA à venir',
        'name_admin_bar' => 'Séance CA à venir',
        'add_new' => 'Ajouter',
        'add_new_item' => 'Ajouter une séance CA à venir',
        'edit_item' => 'Modifier la séance CA à venir',
        'new_item' => 'Nouvelle séance CA à venir',
        'view_item' => 'Voir la séance CA à venir',
        'search_items' => 'Rechercher une séance',
        'not_found' => 'Aucune séance trouvée.',
        'not_found_in_trash' => 'Aucune séance dans la corbeille.',
        'all_items' => 'Gestion séances CA à venir',
    ));

    pio_oremis_register_single_upcoming_post_type('upcoming_ag', array(
        'name' => 'Séances AG à venir',
        'singular_name' => 'Séance AG à venir',
        'menu_name' => 'Séances AG à venir',
        'name_admin_bar' => 'Séance AG à venir',
        'add_new' => 'Ajouter',
        'add_new_item' => 'Ajouter une séance AG à venir',
        'edit_item' => 'Modifier la séance AG à venir',
        'new_item' => 'Nouvelle séance AG à venir',
        'view_item' => 'Voir la séance AG à venir',
        'search_items' => 'Rechercher une séance',
        'not_found' => 'Aucune séance trouvée.',
        'not_found_in_trash' => 'Aucune séance dans la corbeille.',
        'all_items' => 'Gestion séances AG à venir',
    ));
}
add_action('init', 'pio_oremis_register_upcoming_post_types');

function pio_oremis_register_single_upcoming_post_type($post_type, $labels)
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
        'menu_icon' => 'dashicons-calendar-alt',
        'supports' => array('title'),
        'capability_type' => 'post',
        'map_meta_cap' => true,
    ));
}

function pio_oremis_add_upcoming_meta_boxes()
{
    add_meta_box(
        'pio_oremis_upcoming_ca_details',
        'Détails de la séance à venir',
        'pio_oremis_render_upcoming_meta_box',
        'upcoming_ca',
        'normal',
        'high'
    );

    add_meta_box(
        'pio_oremis_upcoming_ag_details',
        'Détails de la séance à venir',
        'pio_oremis_render_upcoming_meta_box',
        'upcoming_ag',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'pio_oremis_add_upcoming_meta_boxes');

function pio_oremis_render_upcoming_meta_box($post)
{
    $session_date = get_post_meta($post->ID, 'session_date', true);
    $agenda_file_url = get_post_meta($post->ID, 'agenda_file_url', true);

    $is_ag = $post->post_type === 'upcoming_ag';
    $nonce_name = $is_ag ? 'pio_oremis_upcoming_ag_nonce' : 'pio_oremis_upcoming_ca_nonce';
    $nonce_action = $is_ag ? 'pio_oremis_save_upcoming_ag_meta' : 'pio_oremis_save_upcoming_ca_meta';

    wp_nonce_field($nonce_action, $nonce_name);

    echo '<p><label for="pio_oremis_upcoming_session_date"><strong>Date de la prochaine séance</strong></label><br />';
    echo '<input type="date" id="pio_oremis_upcoming_session_date" name="pio_oremis_upcoming_session_date" value="' . esc_attr($session_date) . '" /></p>';

    echo '<p><label for="pio_oremis_agenda_file_url"><strong>Ordre du jour (PDF)</strong></label><br />';
    echo '<input type="url" class="regular-text" id="pio_oremis_agenda_file_url" name="pio_oremis_agenda_file_url" value="' . esc_attr($agenda_file_url) . '" />';
    echo ' <button type="button" class="button pio-upcoming-upload" data-target="pio_oremis_agenda_file_url">Choisir un fichier</button>';
    echo ' <button type="button" class="button-link-delete pio-upcoming-clear" data-target="pio_oremis_agenda_file_url">Effacer</button></p>';
}

function pio_oremis_enqueue_upcoming_admin_assets($hook_suffix)
{
    if ($hook_suffix !== 'post.php' && $hook_suffix !== 'post-new.php') {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, array('upcoming_ca', 'upcoming_ag'), true)) {
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

    $(document).on('click', '.pio-upcoming-upload', function (event) {
        event.preventDefault();
        attachMediaPicker($(this));
    });

    $(document).on('click', '.pio-upcoming-clear', function (event) {
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
add_action('admin_enqueue_scripts', 'pio_oremis_enqueue_upcoming_admin_assets');

function pio_oremis_show_upcoming_shortcode_help_notice()
{
    if (!is_admin()) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, array('upcoming_ca', 'upcoming_ag'), true)) {
        return;
    }

    if (!in_array($screen->base, array('edit', 'post'), true)) {
        return;
    }

    $is_ag = $screen->post_type === 'upcoming_ag';
    $shortcode_tag = $is_ag ? 'upcoming_ag_list' : 'upcoming_ca_list';
    $shortcode = '[' . $shortcode_tag . ']';
    $shortcode_posts_per_page = '[' . $shortcode_tag . ' posts_per_page="10" show_pagination="1"]';
    $shortcode_without_pagination = '[' . $shortcode_tag . ' show_pagination="0"]';
    $label = $is_ag ? 'séances AG à venir' : 'séances CA à venir';

    echo '<div class="notice notice-info">';
    echo '<p><strong>Affichage public ' . esc_html($label) . ' :</strong> créez/éditez une page WordPress puis collez le shortcode <code>' . esc_html($shortcode) . '</code>.</p>';
    echo '<p>Options disponibles: <code>' . esc_html($shortcode) . '</code>, <code>' . esc_html($shortcode_posts_per_page) . '</code>, <code>' . esc_html($shortcode_without_pagination) . '</code> (désactive la pagination numérotée).</p>';
    echo '</div>';
}
add_action('admin_notices', 'pio_oremis_show_upcoming_shortcode_help_notice');

function pio_oremis_save_upcoming_ca_meta($post_id)
{
    pio_oremis_save_upcoming_meta($post_id, 'pio_oremis_upcoming_ca_nonce', 'pio_oremis_save_upcoming_ca_meta');
}
add_action('save_post_upcoming_ca', 'pio_oremis_save_upcoming_ca_meta');

function pio_oremis_save_upcoming_ag_meta($post_id)
{
    pio_oremis_save_upcoming_meta($post_id, 'pio_oremis_upcoming_ag_nonce', 'pio_oremis_save_upcoming_ag_meta');
}
add_action('save_post_upcoming_ag', 'pio_oremis_save_upcoming_ag_meta');

function pio_oremis_save_upcoming_meta($post_id, $nonce_name, $nonce_action)
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
    if (isset($_POST['pio_oremis_upcoming_session_date'])) {
        $session_date = sanitize_text_field(wp_unslash($_POST['pio_oremis_upcoming_session_date']));
    }

    if ($session_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $session_date) === 1) {
        update_post_meta($post_id, 'session_date', $session_date);
    } else {
        delete_post_meta($post_id, 'session_date');
    }

    $agenda_file_url = '';
    if (isset($_POST['pio_oremis_agenda_file_url'])) {
        $agenda_file_url = esc_url_raw(trim(wp_unslash($_POST['pio_oremis_agenda_file_url'])));
    }

    if ($agenda_file_url !== '') {
        update_post_meta($post_id, 'agenda_file_url', $agenda_file_url);
    } else {
        delete_post_meta($post_id, 'agenda_file_url');
    }
}

function pio_oremis_render_upcoming_list_shortcode($atts, $shortcode_tag, $post_type, $page_query_arg, $empty_message, $default_title, $pagination_label)
{
    $atts = shortcode_atts(array(
        'posts_per_page' => '10',
        'show_pagination' => '1',
    ), $atts, $shortcode_tag);

    $posts_per_page = max(1, intval($atts['posts_per_page']));
    $show_pagination = in_array(strtolower((string) $atts['show_pagination']), array('1', 'true', 'yes'), true);

    $current_page = 1;
    if (isset($_GET[$page_query_arg])) {
        $current_page = max(1, absint(wp_unslash($_GET[$page_query_arg])));
    }

    $today = wp_date('Y-m-d', current_time('timestamp'));

    $upcoming_query = new WP_Query(array(
        'post_type' => $post_type,
        'post_status' => 'publish',
        'posts_per_page' => $posts_per_page,
        'paged' => $current_page,
        'meta_key' => 'session_date',
        'orderby' => array(
            'meta_value' => 'ASC',
            'date' => 'ASC',
        ),
        'meta_type' => 'DATE',
        'meta_query' => array(
            array(
                'key' => 'session_date',
                'value' => $today,
                'compare' => '>=',
                'type' => 'DATE',
            ),
        ),
        'ignore_sticky_posts' => true,
        'no_found_rows' => !$show_pagination,
    ));

    if (!$upcoming_query->have_posts()) {
        return '<div class="pio-pv-ca-empty">' . esc_html($empty_message) . '</div>';
    }

    wp_enqueue_style('pio-oremis');

    $output = '<div class="pio-pv-ca-list">';

    while ($upcoming_query->have_posts()) {
        $upcoming_query->the_post();
        $post_id = get_the_ID();

        if (function_exists('pio_oremis_format_session_date')) {
            $session_date = pio_oremis_format_session_date(get_post_meta($post_id, 'session_date', true));
        } else {
            $session_date = get_post_meta($post_id, 'session_date', true);
        }
        if ($session_date === '') {
            $session_date = 'Date non renseignée';
        }

        $title = trim((string) get_post_field('post_title', $post_id));
        if ($title === '') {
            $title = $default_title;
        }

        $agenda_file_url = esc_url(get_post_meta($post_id, 'agenda_file_url', true));

        $output .= '<article class="pio-pv-ca-item">';
        $output .= '<h3 class="pio-pv-ca-heading">';
        $output .= '<span class="pio-pv-ca-date">' . esc_html($session_date) . '</span>';
        $output .= '<span class="pio-pv-ca-title">' . esc_html($title) . '</span>';
        $output .= '</h3>';

        if ($agenda_file_url !== '') {
            $output .= '<div class="pio-pv-ca-actions">';
            $output .= '<a class="pio-pv-ca-button" href="' . esc_url($agenda_file_url) . '" target="_blank" rel="noopener">Télécharger l’ordre du jour</a>';
            $output .= '</div>';
        }

        $output .= '</article>';
    }

    $output .= '</div>';

    if ($show_pagination && $upcoming_query->max_num_pages > 1) {
        $base_url = remove_query_arg(array($page_query_arg));
        $pagination = paginate_links(array(
            'base' => esc_url_raw(add_query_arg($page_query_arg, '%#%', $base_url)),
            'format' => '',
            'current' => $current_page,
            'total' => intval($upcoming_query->max_num_pages),
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

function pio_oremis_shortcode_upcoming_ca_list($atts)
{
    return pio_oremis_render_upcoming_list_shortcode(
        $atts,
        'upcoming_ca_list',
        'upcoming_ca',
        'upcoming_ca_page',
        'Aucune séance CA à venir disponible pour le moment.',
        'Prochaine séance du conseil d’administration',
        'Pagination des séances CA à venir'
    );
}
add_shortcode('upcoming_ca_list', 'pio_oremis_shortcode_upcoming_ca_list');

function pio_oremis_shortcode_upcoming_ag_list($atts)
{
    return pio_oremis_render_upcoming_list_shortcode(
        $atts,
        'upcoming_ag_list',
        'upcoming_ag',
        'upcoming_ag_page',
        'Aucune séance AG à venir disponible pour le moment.',
        'Prochaine séance de l’assemblée générale',
        'Pagination des séances AG à venir'
    );
}
add_shortcode('upcoming_ag_list', 'pio_oremis_shortcode_upcoming_ag_list');
