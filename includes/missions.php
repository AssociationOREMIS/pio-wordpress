<?php

/**
 * Volunteer missions (EVA recruitment) from PIO: the list with [pio_missions],
 * one mission in full with [pio_mission id="12"]. Same API settings as the
 * teams (OREMIS > Config PIO); the token needs pio.missions:index for the
 * list and pio.missions:view for one mission. Cached like the teams, and the
 * last missions known stay shown while PIO can't be reached.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GET on the PIO API under /v1, cached cache_ttl seconds. On failure: the
 * last good answer (kept as an option), else the error. A failure is
 * remembered PIO_OREMIS_ERROR_CACHE_TTL seconds so visitors don't each wait
 * for the timeout.
 *
 * @return array|WP_Error the decoded JSON body
 */
function pio_oremis_missions_get($path, $query = array(), $force_refresh = false)
{
    $settings = pio_oremis_get_settings();
    $cache_ttl = intval($settings['cache_ttl']);
    $hash = md5($path . '|' . wp_json_encode($query));
    $cache_key = 'pio_oremis_missions_' . $hash;
    $error_key = 'pio_oremis_missions_error_' . $hash;
    $stale_key = 'pio_oremis_missions_stale_' . $hash;

    if (!$force_refresh && $cache_ttl > 0) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        if (get_transient($error_key) !== false) {
            $stale = get_option($stale_key);
            return is_array($stale) ? $stale : new WP_Error('pio_oremis_api_unavailable', (string) get_transient($error_key));
        }
    }

    $body = pio_oremis_missions_request($path, $query, $settings);

    if (is_wp_error($body)) {
        set_transient($error_key, $body->get_error_message(), PIO_OREMIS_ERROR_CACHE_TTL);
        $stale = get_option($stale_key);
        // A mission gone from PIO (404) isn't shown from an old copy.
        return is_array($stale) && $body->get_error_code() !== 'pio_oremis_not_found' ? $stale : $body;
    }

    delete_transient($error_key);
    update_option($stale_key, $body, false);
    if ($cache_ttl > 0) {
        set_transient($cache_key, $body, $cache_ttl);
    }

    return $body;
}

/**
 * The last failure for this call, or false: shown to admins only, next to the last missions known.
 */
function pio_oremis_missions_last_error($path, $query = array())
{
    $message = get_transient('pio_oremis_missions_error_' . md5($path . '|' . wp_json_encode($query)));

    return $message === false ? false : new WP_Error('pio_oremis_api_unavailable', (string) $message);
}

function pio_oremis_missions_request($path, $query, $settings)
{
    $base_url = untrailingslashit($settings['base_url']);
    $scheme = strtolower((string) parse_url($base_url, PHP_URL_SCHEME));
    $host = parse_url($base_url, PHP_URL_HOST);
    if ($scheme !== 'https') {
        return new WP_Error('pio_oremis_invalid_scheme', 'Base URL must use HTTPS.');
    }
    if (!$host || !pio_oremis_is_allowed_host($host)) {
        return new WP_Error('pio_oremis_invalid_host', 'Base URL host is not allowed.');
    }

    $headers = array('Accept' => 'application/json');
    $api_token = pio_oremis_get_api_token();
    if (!empty($api_token)) {
        $headers['Authorization'] = 'Bearer ' . $api_token;
    }

    $response = wp_safe_remote_get(add_query_arg($query, $base_url . '/v1' . $path), array(
        'headers' => $headers,
        'timeout' => 10,
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code === 404) {
        return new WP_Error('pio_oremis_not_found', 'Mission not found in PIO.');
    }
    if ($code === 401 || $code === 403) {
        return new WP_Error('pio_oremis_forbidden', 'The API token is refused (it needs pio.missions:index, and pio.missions:view for one mission).');
    }
    if ($code < 200 || $code >= 300) {
        return new WP_Error('pio_oremis_http_error', 'Unexpected response code: ' . $code);
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
        return new WP_Error('pio_oremis_invalid_response', 'Invalid API response.');
    }

    return $data;
}

/**
 * "Remote · 2 h par semaine" under a mission's title.
 */
function pio_oremis_mission_details($mission)
{
    $details = array();
    if (!empty($mission['format']['label'])) {
        $details[] = sanitize_text_field($mission['format']['label']);
    }
    if (!empty($mission['commitment'])) {
        $details[] = sanitize_text_field($mission['commitment']);
    }

    return $details === array() ? '' : '<p class="pio-mission-details">' . esc_html(implode(' · ', $details)) . '</p>';
}

/**
 * The image of a mission, or nothing.
 */
function pio_oremis_mission_image($mission, $lazy = true)
{
    if (empty($mission['image_url'])) {
        return '';
    }

    return '<div class="pio-mission-image"><img src="' . esc_url($mission['image_url']) . '" alt="' . esc_attr(isset($mission['image_alt']) ? $mission['image_alt'] : '') . '"'
        . ($lazy ? ' loading="lazy"' : '') . ' decoding="async" referrerpolicy="no-referrer" /></div>';
}

/**
 * [pio_missions]: the published missions as cards, sorted by department
 * and team (group="0" for one plain grid).
 */
function pio_oremis_shortcode_missions($atts)
{
    $atts = shortcode_atts(array(
        // Every open mission by default, so no department is cut off.
        'limit' => '100',
        'format' => '',
        'search' => '',
        'columns' => '3',
        'show_image' => '1',
        'heading_level' => '3',
        'group' => '1',
        'group_heading_level' => '2',
        'department' => '',
        'team' => '',
        'detail_page' => '',
        'class' => '',
    ), $atts, 'pio_missions');

    $query = array('per_page' => max(1, min(100, intval($atts['limit']))));
    if (in_array($atts['format'], array('remote', 'on_site', 'hybrid'), true)) {
        $query['format'] = $atts['format'];
    }
    $search = sanitize_text_field($atts['search']);
    if ($search !== '') {
        $query['search'] = $search;
    }
    foreach (array('department', 'team') as $structure) {
        $code = sanitize_text_field($atts[$structure]);
        if ($code !== '') {
            $query[$structure] = $code;
        }
    }

    $is_admin = current_user_can(pio_oremis_settings_capability());
    $force_refresh = $is_admin && isset($_GET['pio_refresh']) && $_GET['pio_refresh'] === '1';
    $body = pio_oremis_missions_get('/missions', $query, $force_refresh);

    if (is_wp_error($body)) {
        return $is_admin
            ? '<div class="pio-team-error">' . esc_html($body->get_error_message()) . '</div>'
            : '<p class="pio-missions-empty">Les missions ne peuvent pas être affichées pour le moment. Revenez un peu plus tard.</p>';
    }

    $missions = $body['data'];
    if (count($missions) === 0) {
        return '<p class="pio-missions-empty">Aucune mission ouverte pour le moment. Revenez bientôt !</p>';
    }

    $admin_notice = '';
    if ($is_admin) {
        $last_error = pio_oremis_missions_last_error('/missions', $query);
        if (is_wp_error($last_error)) {
            $admin_notice = '<div class="pio-team-error">Last known missions shown (visible to admins only). API error: ' . esc_html($last_error->get_error_message()) . '</div>';
        }
    }

    wp_enqueue_style('pio-oremis');

    $columns = max(1, min(4, intval($atts['columns'])));
    // A page of the site holding [pio_mission]: each card links there with ?mission=ID.
    $card_options = array(
        'show_image' => $atts['show_image'] !== '0',
        'detail_page' => esc_url_raw($atts['detail_page']),
    );
    $classes = array('pio-missions', 'pio-missions--cols-' . $columns);
    $extra_class = sanitize_html_class($atts['class']);
    if ($extra_class !== '') {
        $classes[] = $extra_class;
    }
    $grid_open = '<div class="' . esc_attr(implode(' ', $classes)) . '">';

    // Sorted by department, then team, unless group="0".
    if ($atts['group'] === '0') {
        $level = max(2, min(6, intval($atts['heading_level'])));
        $html = $admin_notice . $grid_open;
        foreach ($missions as $mission) {
            $html .= pio_oremis_mission_card($mission, $level, $card_options);
        }
        return $html . '</div>';
    }

    $department_level = max(2, min(4, intval($atts['group_heading_level'])));
    $html = $admin_notice . '<div class="pio-missions-groups">';

    foreach (pio_oremis_missions_by_structure($missions) as $group) {
        $html .= '<section class="pio-missions-group">';
        $html .= '<h' . $department_level . ' class="pio-missions-group-title">' . esc_html($group['name']) . '</h' . $department_level . '>';

        foreach ($group['teams'] as $team) {
            if ($team['name'] !== '') {
                $html .= '<h' . ($department_level + 1) . ' class="pio-missions-team-title">' . esc_html($team['name']) . '</h' . ($department_level + 1) . '>';
            }
            $mission_level = $department_level + ($team['name'] !== '' ? 2 : 1);
            $html .= $grid_open;
            foreach ($team['missions'] as $mission) {
                $html .= pio_oremis_mission_card($mission, $mission_level, $card_options);
            }
            $html .= '</div>';
        }

        $html .= '</section>';
    }

    return $html . '</div>';
}

/**
 * The missions by department, then team, as PIO sorts them: departments by
 * name, the missions without one last ("Autres missions"); inside a
 * department, the missions aimed at it first, then each team by name.
 *
 * @return array<int, array{name: string, teams: array<int, array{name: string, missions: array}>}>
 */
function pio_oremis_missions_by_structure($missions)
{
    $groups = array();

    foreach ($missions as $mission) {
        if (!is_array($mission) || empty($mission['title'])) {
            continue;
        }
        $department = isset($mission['department']['name']) ? sanitize_text_field($mission['department']['name']) : '';
        $team = isset($mission['team']['name']) ? sanitize_text_field($mission['team']['name']) : '';
        $groups[$department][$team][] = $mission;
    }

    uksort($groups, function ($a, $b) {
        if ($a === '' || $b === '') {
            return $a === '' ? ($b === '' ? 0 : 1) : -1;
        }
        return strcasecmp(remove_accents($a), remove_accents($b));
    });

    $sorted = array();
    foreach ($groups as $department => $teams) {
        uksort($teams, function ($a, $b) {
            if ($a === '' || $b === '') {
                return $a === '' ? ($b === '' ? 0 : -1) : 1;
            }
            return strcasecmp(remove_accents($a), remove_accents($b));
        });

        $team_list = array();
        foreach ($teams as $team => $team_missions) {
            $team_list[] = array('name' => (string) $team, 'missions' => $team_missions);
        }
        $sorted[] = array('name' => $department !== '' ? (string) $department : 'Autres missions', 'teams' => $team_list);
    }

    return $sorted;
}

/**
 * One mission card: image, title, format and commitment, summary, link.
 */
function pio_oremis_mission_card($mission, $heading_level, $options)
{
    if (!is_array($mission) || empty($mission['title'])) {
        return '';
    }

    $heading = 'h' . max(2, min(6, intval($heading_level)));
    $title = sanitize_text_field($mission['title']);
    $detail_page = $options['detail_page'];
    $more_url = $detail_page !== '' && !empty($mission['id'])
        ? add_query_arg('mission', intval($mission['id']), $detail_page)
        : (isset($mission['apply_url']) ? $mission['apply_url'] : '');

    $html = '<article class="pio-mission">';
    if ($options['show_image']) {
        $html .= pio_oremis_mission_image($mission);
    }
    $html .= '<div class="pio-mission-body">';
    $html .= '<' . $heading . ' class="pio-mission-title">' . esc_html($title) . '</' . $heading . '>';
    $html .= pio_oremis_mission_details($mission);
    if (!empty($mission['summary'])) {
        $html .= '<p class="pio-mission-summary">' . esc_html(sanitize_text_field($mission['summary'])) . '</p>';
    }
    if ($more_url !== '') {
        $html .= '<p class="pio-mission-actions"><a class="pio-mission-link" href="' . esc_url($more_url) . '">'
            . ($detail_page !== '' ? 'Découvrir la mission' : 'Découvrir et postuler')
            . '<span class="screen-reader-text"> : ' . esc_html($title) . '</span></a></p>';
    }

    return $html . '</div></article>';
}

add_shortcode('pio_missions', 'pio_oremis_shortcode_missions');

/**
 * [pio_mission id="12"]: one mission in full (texts, how it goes, apply
 * button). Without id, the ?mission=ID of the address (from [pio_missions
 * detail_page="..."]). A draft or closed mission isn't shown.
 */
function pio_oremis_shortcode_mission($atts)
{
    $atts = shortcode_atts(array(
        'id' => '',
        'heading_level' => '2',
        'class' => '',
    ), $atts, 'pio_mission');

    $id = intval($atts['id']);
    if ($id <= 0 && isset($_GET['mission'])) {
        $id = absint(wp_unslash($_GET['mission']));
    }

    $is_admin = current_user_can(pio_oremis_settings_capability());
    if ($id <= 0) {
        return $is_admin ? '<div class="pio-team-error">[pio_mission] needs id="..." (or ?mission=ID in the address).</div>' : '';
    }

    $force_refresh = $is_admin && isset($_GET['pio_refresh']) && $_GET['pio_refresh'] === '1';
    $body = pio_oremis_missions_get('/missions/' . $id, array(), $force_refresh);

    if (is_wp_error($body)) {
        if ($body->get_error_code() === 'pio_oremis_not_found') {
            return '<p class="pio-missions-empty">Cette mission n\'existe plus.</p>';
        }

        return $is_admin
            ? '<div class="pio-team-error">' . esc_html($body->get_error_message()) . '</div>'
            : '<p class="pio-missions-empty">Cette mission ne peut pas être affichée pour le moment. Revenez un peu plus tard.</p>';
    }

    $mission = $body['data'];
    if (!isset($mission['status']['value']) || $mission['status']['value'] !== 'published') {
        return '<p class="pio-missions-empty">Cette mission n\'est plus ouverte.</p>';
    }

    wp_enqueue_style('pio-oremis');

    $level = max(2, min(5, intval($atts['heading_level'])));
    $heading = 'h' . $level;
    $subheading = 'h' . ($level + 1);
    $classes = array('pio-mission-full');
    $extra_class = sanitize_html_class($atts['class']);
    if ($extra_class !== '') {
        $classes[] = $extra_class;
    }

    $html = '<article class="' . esc_attr(implode(' ', $classes)) . '">';
    $html .= pio_oremis_mission_image($mission, false);
    $html .= '<' . $heading . ' class="pio-mission-title">' . esc_html(sanitize_text_field($mission['title'])) . '</' . $heading . '>';
    $html .= pio_oremis_mission_details($mission);
    if (!empty($mission['summary'])) {
        $html .= '<p class="pio-mission-lead">' . esc_html(sanitize_text_field($mission['summary'])) . '</p>';
    }

    // PIO sends sanitized HTML; wp_kses_post() keeps WordPress's own guarantee.
    $sections = array('description' => 'La mission', 'activities' => 'Ce que vous ferez', 'benefits' => 'Ce que vous y gagnez');
    foreach ($sections as $field => $label) {
        if (!empty($mission[$field])) {
            $html .= '<' . $subheading . '>' . esc_html($label) . '</' . $subheading . '>' . wp_kses_post($mission[$field]);
        }
    }

    $steps = isset($mission['steps']) && is_array($mission['steps']) ? $mission['steps'] : array();
    if ($steps !== array()) {
        $html .= '<' . $subheading . '>Comment ça se passe</' . $subheading . '><ol class="pio-mission-steps">';
        if (!empty($mission['questions'])) {
            $html .= '<li>Vous répondez à quelques questions.</li>';
        }
        foreach ($steps as $step) {
            $step_title = isset($step['title']) ? sanitize_text_field($step['title']) : '';
            $step_type = isset($step['type']['label']) ? sanitize_text_field($step['type']['label']) : '';
            $about = isset($step['candidate_description']) ? sanitize_text_field($step['candidate_description']) : '';
            $html .= '<li><span class="pio-mission-step-title">' . esc_html($step_title) . '</span>'
                . ($step_type !== '' && $step_type !== $step_title ? ' <span class="pio-mission-step-type">(' . esc_html($step_type) . ')</span>' : '')
                . ($about !== '' ? '<br><span class="pio-mission-step-about">' . esc_html($about) . '</span>' : '')
                . '</li>';
        }
        $html .= '</ol>';
    }

    if (!empty($mission['response_delay_days'])) {
        $html .= '<p class="pio-mission-details">Une réponse sous ' . intval($mission['response_delay_days']) . ' jours environ.</p>';
    }

    if (!empty($mission['apply_url'])) {
        $html .= '<p class="pio-mission-actions"><a class="pio-mission-apply wp-element-button" href="' . esc_url($mission['apply_url']) . '">Postuler à cette mission</a></p>';
    }

    return $html . '</article>';
}
add_shortcode('pio_mission', 'pio_oremis_shortcode_mission');
