<?php
/**
 * Plugin Name: LionChat Lead Integrator
 * Description: Integração nativa WordPress/Elementor com o LionChat — tags, inboxes, respostas prontas, templates WhatsApp Cloud API, automações e mensagens automáticas.
 * Version: 2.6
 * Author: LionChat
 * Author URI: https://lionchat.com.br
 * Text Domain: lionchat-lead
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LION_VERSION', '2.6' );

// ============================================================
// LOGO SVG (inline, usado no menu e header)
// ============================================================
function lion_get_logo_svg( $fill = '#f2a900' ) {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 600" width="20" height="20"><path d="M350.801,451.955l-76.331,43.726c-1.316,0.754-2.934,0.75-4.246-0.011c-1.312-0.761-2.12-2.162-2.12-3.679l0-18.141c0-10.475-5.776-20.096-15.021-25.02l-115.149-61.337c-11.094-5.909-18.025-17.455-18.025-30.025l0-134.738c0-12.154 6.484-23.384 17.009-29.461l87.432-50.479l0,176.177c0,12.525 10.154,22.679 22.679,22.679l92.432,0c6.263,0 11.339,5.077 11.339,11.339l0,98.97Zm-93.308-328.3l26.572-15.341c9.943-5.74 22.11-6.079 32.357-0.899l144.999,73.299c11.451,5.789 18.671,17.528 18.671,30.36l0,147.1c0,12.195-6.527,23.457-17.109,29.518l-79.038,45.277l0-101.272c0-12.525-10.154-22.679-22.679-22.679l-92.432,0c-6.263,0-11.339-5.077-11.339-11.339l0-174.023Z" style="fill:' . esc_attr( $fill ) . '"/></svg>';
}

function lion_get_menu_icon() {
    return 'data:image/svg+xml;base64,' . base64_encode( lion_get_logo_svg( '#a7aaad' ) );
}

// ============================================================
// LOG (máx 100 entradas, credenciais mascaradas)
// ============================================================
function lion_log( $message, $level = 'INFO' ) {
    $logs = get_option( 'lion_debug_logs', [] );
    if ( ! is_array( $logs ) ) $logs = [];
    if ( count( $logs ) > 100 ) array_shift( $logs );
    $safe = preg_replace( '/api_access_token=[^&\s]+/', 'api_access_token=***', $message );
    $logs[] = '[' . current_time( 'Y-m-d H:i:s' ) . "] [$level] $safe";
    update_option( 'lion_debug_logs', $logs );
}

// ============================================================
// HELPER: LionChat API (suporta override de credenciais via $creds)
// ============================================================
function lion_api( $method, $path, $body = null, $creds = null ) {
    $url   = rtrim( $creds['url'] ?? get_option( 'lion_url', 'https://app.lionchat.com.br' ), '/' );
    $acc   = $creds['acc'] ?? get_option( 'lion_acc' );
    $token = $creds['token'] ?? get_option( 'lion_token' );
    if ( empty( $url ) || empty( $acc ) || empty( $token ) ) {
        return new WP_Error( 'lion_no_config', 'Credenciais não configuradas.' );
    }
    $endpoint = "$url/api/v1/accounts/$acc$path";
    $headers  = [ 'Content-Type' => 'application/json', 'api_access_token' => $token ];
    $args     = [ 'headers' => $headers, 'sslverify' => false, 'timeout' => 15 ];
    if ( $method === 'GET' ) {
        $res = wp_remote_get( $endpoint, $args );
    } else {
        $args['body'] = $body ? json_encode( $body ) : '{}';
        $res = wp_remote_post( $endpoint, $args );
    }
    if ( is_wp_error( $res ) ) {
        // Retry 1x após 1 segundo em caso de falha de rede
        usleep( 1000000 );
        $res = ( $method === 'GET' ) ? wp_remote_get( $endpoint, $args ) : wp_remote_post( $endpoint, $args );
        if ( is_wp_error( $res ) ) return $res;
    }
    $code = wp_remote_retrieve_response_code( $res );
    $data = json_decode( wp_remote_retrieve_body( $res ), true );
    if ( $code >= 400 ) {
        return new WP_Error( 'lion_api_error', $data['error'] ?? $data['message'] ?? "HTTP $code" );
    }
    return $data;
}

// Helper: PATCH request (lion_api so suporta GET/POST)
function lion_api_patch( $path, $body = null, $creds = null ) {
    $url   = rtrim( $creds['url'] ?? get_option( 'lion_url', 'https://app.lionchat.com.br' ), '/' );
    $acc   = $creds['acc'] ?? get_option( 'lion_acc' );
    $token = $creds['token'] ?? get_option( 'lion_token' );
    if ( empty( $url ) || empty( $acc ) || empty( $token ) ) {
        return new WP_Error( 'lion_no_config', 'Credenciais não configuradas.' );
    }
    $endpoint = "$url/api/v1/accounts/$acc$path";
    $args = [
        'method'    => 'PATCH',
        'headers'   => [ 'Content-Type' => 'application/json', 'api_access_token' => $token ],
        'body'      => $body ? json_encode( $body ) : '{}',
        'sslverify' => false,
        'timeout'   => 15,
    ];
    $res = wp_remote_request( $endpoint, $args );
    if ( is_wp_error( $res ) ) return $res;
    $code = wp_remote_retrieve_response_code( $res );
    $data = json_decode( wp_remote_retrieve_body( $res ), true );
    if ( $code >= 400 ) {
        return new WP_Error( 'lion_api_error', $data['error'] ?? $data['message'] ?? "HTTP $code" );
    }
    return $data;
}

// Helper: extrair credenciais override do $_POST (formulário não salvo)
function lion_get_ajax_creds() {
    $url   = sanitize_text_field( $_POST['live_url'] ?? '' );
    $acc   = sanitize_text_field( $_POST['live_acc'] ?? '' );
    $token = sanitize_text_field( $_POST['live_token'] ?? '' );
    if ( ! empty( $url ) && ! empty( $acc ) && ! empty( $token ) ) {
        return [ 'url' => $url, 'acc' => $acc, 'token' => $token ];
    }
    return null;
}

// ============================================================
// UTM TRACKING: Captura parametros da URL e salva em cookie (frontend publico)
// ============================================================
function lion_get_utm_keys() {
    return array(
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'gbraid', 'wbraid', 'fbclid',
        'ctwa_clid', 'ctwa_source_id', 'ctwa_source_url', 'ctwa_source_type'
    );
}

add_action( 'wp_head', function() {
    ?>
    <script>
    (function() {
        var keys = <?php echo json_encode( lion_get_utm_keys() ); ?>;
        var params = new URLSearchParams(window.location.search);
        var secure = location.protocol === 'https:' ? ';Secure' : '';
        var expires = new Date(Date.now() + 30*24*60*60*1000).toUTCString();
        var found = {};
        keys.forEach(function(k) {
            var v = params.get(k);
            if (v) found[k] = v;
        });
        var existing = {};
        try {
            var match = document.cookie.match(/(?:^|;\s*)lion_utm=([^;]*)/);
            if (match) existing = JSON.parse(decodeURIComponent(match[1]));
        } catch(e) {}
        if (Object.keys(found).length > 0) {
            var merged = Object.assign(existing, found);
            document.cookie = 'lion_utm=' + encodeURIComponent(JSON.stringify(merged)) + ';expires=' + expires + ';path=/;SameSite=Lax' + secure;
        } else if (Object.keys(existing).length > 0) {
            // Renovar expiracao do cookie mesmo sem UTMs novos (30 dias desde ultima visita)
            document.cookie = 'lion_utm=' + encodeURIComponent(JSON.stringify(existing)) + ';expires=' + expires + ';path=/;SameSite=Lax' + secure;
        }
    })();

    // AIDEV-NOTE: Integração LionTrack — quando formulário é submetido,
    // chama LionTrack.identify() pra vincular o visitor ao contato que o plugin vai criar.
    document.addEventListener('submit', function(e) {
        if (!window.LionTrack || !window.LionTrack.identify) return;
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        var data = {};
        var inputs = form.querySelectorAll('input, select, textarea');
        for (var i = 0; i < inputs.length; i++) {
            var el = inputs[i];
            var name = (el.name || el.id || el.placeholder || '').toLowerCase();
            var val = (el.value || '').trim();
            if (!val) continue;
            if (/mail/.test(name) && val.indexOf('@') > 0) data.email = val;
            else if (/tel|cel|whats|fone|phone/.test(name)) data.phone = val;
            else if (/nom|name|user|full/.test(name) && !data.name) data.name = val;
        }
        if (data.email || data.phone) {
            window.LionTrack.identify(data);
        }
    }, true);
    </script>
    <?php

    // AIDEV-NOTE: LionTrack — injeta pixel de rastreamento se toggle ativado
    if ( get_option( 'lion_liontrack_enabled' ) === '1' ) {
        $url = rtrim( get_option( 'lion_url', 'https://app.lionchat.com.br' ), '/' );
        $acc = get_option( 'lion_acc' );
        $token = get_option( 'lion_token' );
        // Busca o liontrack_token da conta via API (cacheado por 1 hora)
        $lt_token = get_transient( 'lion_liontrack_token' );
        if ( false === $lt_token && ! empty( $url ) && ! empty( $acc ) && ! empty( $token ) ) {
            $res = lion_api( 'GET', '' );
            if ( ! is_wp_error( $res ) ) {
                $lt_token = $res['liontrack_token'] ?? '';
                set_transient( 'lion_liontrack_token', $lt_token, HOUR_IN_SECONDS );
            }
        }
        if ( ! empty( $lt_token ) ) {
            ?>
            <script src="<?php echo esc_url( $url . '/liontrack.js' ); ?>" onload="LionTrack.init({ token: '<?php echo esc_attr( $lt_token ); ?>' })"></script>
            <?php
        }
    }
});

/**
 * Le UTMs do cookie lion_utm (set pelo JS no frontend).
 * Retorna array associativo com apenas valores nao-vazios.
 */
function lion_get_utm_from_cookie() {
    if ( empty( $_COOKIE['lion_utm'] ) ) return [];
    $data = json_decode( stripslashes( $_COOKIE['lion_utm'] ), true );
    if ( ! is_array( $data ) ) return [];
    $clean = [];
    foreach ( $data as $key => $value ) {
        if ( in_array( $key, lion_get_utm_keys(), true ) && ! empty( $value ) ) {
            $clean[ $key ] = sanitize_text_field( $value );
        }
    }
    return $clean;
}

// ============================================================
// ADMIN MENU
// ============================================================
add_action( 'admin_menu', function() {
    add_menu_page( 'LionChat', 'LionChat', 'manage_options', 'lion_lead_conf', 'lion_render_main_page', lion_get_menu_icon(), 26 );
    add_submenu_page( 'lion_lead_conf', 'Configurações', 'Configurações', 'manage_options', 'lion_lead_conf' );
    add_submenu_page( 'lion_lead_conf', 'Logs', 'Logs', 'manage_options', 'lion_logs', 'lion_render_logs_page' );
});

// ============================================================
// REGISTER SETTINGS
// ============================================================
add_action( 'admin_init', function() {
    foreach ( [ 'lion_url', 'lion_acc', 'lion_token', 'lion_inbox', 'lion_outbox', 'lion_custom_rules', 'lion_liontrack_enabled' ] as $o ) {
        register_setting( 'lion_opts_group', $o );
    }
});

// ============================================================
// AJAX: Testar conexão
// ============================================================
add_action( 'wp_ajax_lion_test_connection', function() {
    check_ajax_referer( 'lion_admin_nonce', 'nonce' );
    $url   = sanitize_text_field( $_POST['url'] ?? '' );
    $acc   = sanitize_text_field( $_POST['acc'] ?? '' );
    $token = sanitize_text_field( $_POST['token'] ?? '' );
    if ( empty( $url ) || empty( $acc ) || empty( $token ) ) {
        wp_send_json_error( 'Preencha todos os campos de conexão.' );
    }
    $res = wp_remote_get( rtrim( $url, '/' ) . "/api/v1/accounts/$acc/inboxes", [
        'headers' => [ 'api_access_token' => $token, 'Content-Type' => 'application/json' ],
        'sslverify' => false, 'timeout' => 10,
    ]);
    if ( is_wp_error( $res ) ) wp_send_json_error( 'Erro: ' . $res->get_error_message() );
    $code = wp_remote_retrieve_response_code( $res );
    if ( $code >= 400 ) wp_send_json_error( "Erro HTTP $code — verifique URL, token e ID da conta." );
    $data = json_decode( wp_remote_retrieve_body( $res ), true );
    wp_send_json_success( [ 'message' => 'Conectado com sucesso!', 'inbox_count' => count( $data['payload'] ?? [] ) ] );
});

// ============================================================
// AJAX: Buscar inboxes
// ============================================================
add_action( 'wp_ajax_lion_fetch_inboxes', function() {
    check_ajax_referer( 'lion_admin_nonce', 'nonce' );
    $creds = lion_get_ajax_creds();
    $data = lion_api( 'GET', '/inboxes', null, $creds );
    if ( is_wp_error( $data ) ) wp_send_json_error( $data->get_error_message() );
    $items = [];
    foreach ( ( $data['payload'] ?? [] ) as $ib ) {
        $map = [
            'Channel::Waha' => 'WhatsApp', 'Channel::Whatsapp' => 'WhatsApp Cloud',
            'Channel::WebWidget' => 'Web Widget', 'Channel::Api' => 'API',
            'Channel::Email' => 'Email', 'Channel::Telegram' => 'Telegram',
            'Channel::FacebookPage' => 'Facebook',
        ];
        $channel_type = $ib['channel_type'] ?? '';
        $item = [
            'id'           => $ib['id'],
            'name'         => $ib['name'],
            'type'         => $map[ $channel_type ] ?? 'Outro',
            'channel_type' => $channel_type,
        ];
        // Cloud API: busca templates — tenta da listagem, senão busca individual
        if ( $channel_type === 'Channel::Whatsapp' ) {
            $tpl_source = $ib['message_templates'] ?? [];
            // Se a listagem não trouxe templates, busca o inbox individual
            if ( empty( $tpl_source ) ) {
                $individual = lion_api( 'GET', '/inboxes/' . $ib['id'], null, $creds );
                if ( ! is_wp_error( $individual ) ) {
                    $tpl_source = $individual['message_templates'] ?? [];
                }
            }
            $templates = [];
            if ( ! empty( $tpl_source ) ) {
                foreach ( $tpl_source as $tpl ) {
                    if ( strtolower( $tpl['status'] ?? '' ) !== 'approved' ) continue;
                    $components = $tpl['components'] ?? [];
                    $body_vars_count = 0;
                    $header_vars_count = 0;
                    $body_text = '';
                    foreach ( $components as $comp ) {
                        if ( $comp['type'] === 'BODY' ) {
                            $body_text = $comp['text'] ?? '';
                            if ( ! empty( $comp['example']['body_text'][0] ) ) {
                                $body_vars_count = count( $comp['example']['body_text'][0] );
                            } else {
                                preg_match_all( '/\{\{(\d+)\}\}/', $body_text, $m );
                                $body_vars_count = count( $m[1] );
                            }
                        }
                        if ( $comp['type'] === 'HEADER' && ( $comp['format'] ?? '' ) === 'TEXT' ) {
                            preg_match_all( '/\{\{(\d+)\}\}/', $comp['text'] ?? '', $m );
                            $header_vars_count = count( $m[1] );
                        }
                    }
                    $templates[] = [
                        'name'        => $tpl['name'],
                        'language'    => $tpl['language'] ?? 'pt_BR',
                        'body'        => $body_text,
                        'body_vars'   => $body_vars_count,
                        'header_vars' => $header_vars_count,
                        'components'  => $components,
                    ];
                }
            }
            $item['templates'] = $templates;
        }
        $items[] = $item;
    }
    wp_send_json_success( $items );
});

// ============================================================
// AJAX: Buscar labels
// ============================================================
add_action( 'wp_ajax_lion_fetch_labels', function() {
    check_ajax_referer( 'lion_admin_nonce', 'nonce' );
    $data = lion_api( 'GET', '/labels', null, lion_get_ajax_creds() );
    if ( is_wp_error( $data ) ) wp_send_json_error( $data->get_error_message() );
    $items = [];
    foreach ( ( $data['payload'] ?? [] ) as $l ) {
        $items[] = [ 'id' => $l['id'], 'title' => $l['title'], 'color' => $l['color'] ?? '#999' ];
    }
    wp_send_json_success( $items );
});

// ============================================================
// AJAX: Buscar respostas prontas
// ============================================================
add_action( 'wp_ajax_lion_fetch_canned', function() {
    check_ajax_referer( 'lion_admin_nonce', 'nonce' );
    $data = lion_api( 'GET', '/canned_responses', null, lion_get_ajax_creds() );
    if ( is_wp_error( $data ) ) wp_send_json_error( $data->get_error_message() );
    $items = [];
    foreach ( ( $data ?? [] ) as $cr ) {
        $blocks = $cr['blocks'] ?? [];
        $block_summary = [];
        foreach ( $blocks as $b ) {
            $t = $b['type'] ?? 'text';
            if ( ! isset( $block_summary[$t] ) ) $block_summary[$t] = 0;
            $block_summary[$t]++;
        }
        $summary_parts = [];
        $type_labels = [ 'text' => 'texto', 'image' => 'imagem', 'audio' => 'áudio', 'file' => 'arquivo', 'video' => 'vídeo' ];
        foreach ( $block_summary as $type => $count ) {
            $label = $type_labels[$type] ?? $type;
            $summary_parts[] = $count . ' ' . $label . ( $count > 1 ? 's' : '' );
        }
        $items[] = [
            'id'         => $cr['id'],
            'short_code' => $cr['short_code'] ?? '',
            'content'    => wp_trim_words( $cr['content'] ?? '', 12, '...' ),
            'blocks'     => count( $blocks ),
            'summary'    => ! empty( $summary_parts ) ? implode( ', ', $summary_parts ) : 'mensagem simples',
        ];
    }
    wp_send_json_success( $items );
});

// ============================================================
// AJAX: Buscar automações (com ação webhook)
// ============================================================
add_action( 'wp_ajax_lion_fetch_automations', function() {
    check_ajax_referer( 'lion_admin_nonce', 'nonce' );
    $data = lion_api( 'GET', '/automation_rules', null, lion_get_ajax_creds() );
    if ( is_wp_error( $data ) ) wp_send_json_error( $data->get_error_message() );
    $items = [];
    foreach ( ( $data['payload'] ?? [] ) as $rule ) {
        if ( ! ( $rule['active'] ?? false ) ) continue;
        // Filtra apenas automações com evento "webhook" (disparadas por fontes externas)
        if ( ( $rule['event_name'] ?? '' ) !== 'webhook' ) continue;
        $items[] = [
            'id'       => $rule['id'],
            'name'     => $rule['name'],
            'event'    => 'webhook',
            'inbox_id' => $rule['inbox_id'] ?? null,
        ];
    }
    wp_send_json_success( $items );
});

// ============================================================
// AJAX: Buscar formulários (Elementor + CF7 + WPForms + detectados)
// ============================================================
add_action( 'wp_ajax_lion_fetch_forms', function() {
    check_ajax_referer( 'lion_admin_nonce', 'nonce' );
    $forms = [];
    $detected = get_option( 'lion_detected_forms', [] );
    if ( is_array( $detected ) ) {
        foreach ( $detected as $f ) $forms[$f] = $f;
    }
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_elementor_data' AND meta_value LIKE '%\"widgetType\":\"form\"%' LIMIT 200", ARRAY_A
    );
    foreach ( $rows as $row ) {
        $els = json_decode( $row['meta_value'], true );
        if ( is_array( $els ) ) lion_extract_form_names( $els, $forms );
    }
    if ( class_exists( 'WPCF7_ContactForm' ) ) {
        foreach ( get_posts( [ 'post_type' => 'wpcf7_contact_form', 'posts_per_page' => -1 ] ) as $p ) {
            $forms[ 'CF7: ' . $p->post_title ] = 'CF7: ' . $p->post_title;
        }
    }
    if ( function_exists( 'wpforms' ) ) {
        foreach ( get_posts( [ 'post_type' => 'wpforms', 'posts_per_page' => -1 ] ) as $p ) {
            $forms[ 'WPForms: ' . $p->post_title ] = 'WPForms: ' . $p->post_title;
        }
    }
    wp_send_json_success( array_values( $forms ) );
});

function lion_extract_form_names( $elements, &$forms ) {
    foreach ( $elements as $el ) {
        if ( ( $el['widgetType'] ?? '' ) === 'form' && ! empty( $el['settings']['form_name'] ) ) {
            $forms[ $el['settings']['form_name'] ] = $el['settings']['form_name'];
        }
        if ( ! empty( $el['elements'] ) ) lion_extract_form_names( $el['elements'], $forms );
    }
}

// ============================================================
// TAG SYNC (ao salvar)
// ============================================================
add_filter( 'pre_update_option_lion_custom_rules', function( $new_rules, $old_rules ) {
    if ( ! is_array( $new_rules ) ) return $new_rules;
    $data = lion_api( 'GET', '/labels' );
    if ( is_wp_error( $data ) ) return $new_rules;
    $existing = array_map( function( $l ) { return strtolower( $l['title'] ); }, $data['payload'] ?? [] );
    foreach ( $new_rules as $rule ) {
        $tag_raw = trim( sanitize_text_field( $rule['tag'] ?? '' ) );
        $tags = array_filter( array_map( 'trim', explode( ',', $tag_raw ) ) );
        foreach ( $tags as $tag ) {
            if ( ! empty( $tag ) && ! in_array( strtolower( $tag ), $existing ) ) {
                lion_api( 'POST', '/labels', [ 'label' => [ 'title' => $tag, 'description' => 'Criada via LionChat WP', 'color' => '#f2a900' ] ] );
                $existing[] = strtolower( $tag );
                lion_log( "Tag '$tag' criada no LionChat.", 'SYNC' );
            }
        }
    }
    return $new_rules;
}, 10, 2 );

// ============================================================
// PÁGINA PRINCIPAL
// ============================================================
function lion_render_main_page() {
    $rules        = get_option( 'lion_custom_rules', [] );
    $nonce        = wp_create_nonce( 'lion_admin_nonce' );
    $saved_inbox  = get_option( 'lion_inbox', '' );
    $saved_outbox = get_option( 'lion_outbox', '' );
    $has_creds    = ! empty( get_option( 'lion_url' ) ) && ! empty( get_option( 'lion_acc' ) ) && ! empty( get_option( 'lion_token' ) );
    if ( ! is_array( $rules ) ) $rules = [];
    ?>
    <style>
        /* Reset */
        .lion-wrap *, .lion-wrap *::before, .lion-wrap *::after { box-sizing: border-box; }

        /* Layout */
        .lion-wrap { max-width: 960px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }

        /* Header */
        .lion-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            padding: 24px 28px; border-radius: 12px; margin-bottom: 24px;
            display: flex; align-items: center; gap: 16px;
        }
        .lion-header-logo { flex-shrink: 0; }
        .lion-header-logo svg { width: 40px; height: 40px; }
        .lion-header-text h1 { margin: 0; font-size: 20px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 10px; }
        .lion-header-text p { margin: 6px 0 0; font-size: 13px; color: #94a3b8; }
        .lion-badge { font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 20px; }
        .lion-badge-version { background: #f2a900; color: #1a1a2e; }
        .lion-badge-connected { background: #166534; color: #bbf7d0; }
        .lion-badge-disconnected { background: #7f1d1d; color: #fecaca; }
        .lion-header-right { margin-left: auto; }

        /* Cards */
        .lion-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .lion-card-title { margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 8px; }
        .lion-card-title .dashicons { color: #f2a900; }
        .lion-card-desc { font-size: 13px; color: #64748b; margin: 0 0 18px; }

        /* Fields */
        .lion-field { margin-bottom: 14px; }
        .lion-field label { display: block; font-weight: 600; font-size: 12px; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .3px; }
        .lion-field input, .lion-field select {
            width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 14px; transition: all .2s; background: #fff; color: #1e293b;
        }
        .lion-field input:focus, .lion-field select:focus { border-color: #f2a900; outline: none; box-shadow: 0 0 0 3px rgba(242,169,0,.12); }
        .lion-field .hint { font-size: 11px; color: #94a3b8; margin-top: 3px; }
        .lion-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        /* Buttons */
        .lion-btn {
            display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 8px;
            font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid transparent; transition: all .15s;
        }
        .lion-btn-primary { background: #f2a900; color: #1a1a2e; border-color: #f2a900; }
        .lion-btn-primary:hover { background: #d4940a; border-color: #d4940a; }
        .lion-btn-secondary { background: #f8fafc; color: #475569; border-color: #cbd5e1; }
        .lion-btn-secondary:hover { background: #f1f5f9; border-color: #94a3b8; }
        .lion-btn-danger { background: #fff; color: #dc2626; border-color: #fca5a5; }
        .lion-btn-danger:hover { background: #fef2f2; }
        .lion-btn[disabled] { opacity: .5; cursor: not-allowed; }

        /* Inline status */
        .lion-inline-status { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; font-weight: 500; margin-left: 12px; vertical-align: middle; }
        .lion-inline-status.ok { color: #16a34a; }
        .lion-inline-status.err { color: #dc2626; }
        .lion-inline-status.loading { color: #ca8a04; }
        .lion-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .lion-dot.green { background: #22c55e; }
        .lion-dot.red { background: #ef4444; }
        .lion-dot.yellow { background: #eab308; }

        /* Rules */
        .lion-rule {
            background: #fafbfc; border: 1px solid #e2e8f0; border-left: 4px solid #f2a900;
            border-radius: 0 10px 10px 0; padding: 18px; margin-bottom: 14px;
        }
        .lion-rule-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .lion-rule-num { background: #f2a900; color: #1a1a2e; width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; }
        .lion-rule .lion-field select, .lion-rule .lion-field input { background: #fff; }

        /* Action type toggle */
        .lion-action-toggle { display: flex; gap: 0; margin-bottom: 14px; width: 100%; }
        .lion-action-toggle input { display: none; }
        .lion-action-toggle label {
            flex: 1 1 0%; padding: 10px 14px; text-align: center; font-size: 13px; font-weight: 600;
            border: 1px solid #cbd5e1; cursor: pointer; color: #64748b; transition: all .15s;
            text-transform: none; letter-spacing: 0; display: flex; align-items: center; justify-content: center; gap: 6px;
            margin-left: -1px; min-width: 0;
        }
        .lion-action-toggle label:first-of-type { border-radius: 8px 0 0 8px; margin-left: 0; }
        .lion-action-toggle label:last-of-type { border-radius: 0 8px 8px 0; }
        .lion-action-toggle input:checked + label { background: #f2a900; color: #1a1a2e; border-color: #f2a900; z-index: 1; position: relative; }

        /* Action panels — largura total */
        .lion-action-panel { width: 100%; }

        /* Canned preview */
        .lion-canned-preview { font-size: 11px; color: #94a3b8; margin-top: 4px; display: flex; align-items: center; gap: 4px; }
        .lion-canned-preview .dashicons { font-size: 14px; width: 14px; height: 14px; }

        /* Inbox mismatch warning */
        .lion-inbox-warn { background: #fefce8; border: 1px solid #fde68a; border-radius: 6px; padding: 8px 12px; font-size: 12px; color: #92400e; margin-top: 8px; display: none; align-items: center; gap: 6px; }
        .lion-inbox-warn .dashicons { font-size: 16px; width: 16px; height: 16px; color: #ca8a04; }

        /* Template variables */
        .lion-tpl-preview { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 14px; margin-top: 10px; font-size: 13px; color: #166534; line-height: 1.6; white-space: pre-wrap; }
        .lion-tpl-preview .tpl-var { background: #dcfce7; padding: 1px 6px; border-radius: 4px; font-weight: 600; font-family: monospace; }
        .lion-tpl-vars { margin-top: 10px; }
        .lion-tpl-vars .lion-field { margin-bottom: 10px; }
        .lion-tpl-vars .lion-field label { font-size: 11px; color: #166534; }
        .lion-tpl-vars .lion-field select { font-size: 13px; }
        .lion-tpl-info { font-size: 11px; color: #64748b; margin-top: 6px; display: flex; align-items: center; gap: 4px; }
        .lion-tpl-info .dashicons { font-size: 14px; width: 14px; height: 14px; }
        .lion-cloud-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; color: #166534; background: #dcfce7; padding: 2px 8px; border-radius: 10px; }
        .lion-waha-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; color: #1e40af; background: #dbeafe; padding: 2px 8px; border-radius: 10px; }

        /* Empty state */
        .lion-empty { text-align: center; padding: 40px 20px; color: #94a3b8; }
        .lion-empty .dashicons { font-size: 40px; width: 40px; height: 40px; margin-bottom: 8px; display: block; margin-left: auto; margin-right: auto; opacity: .5; }
        .lion-empty p { margin: 0; font-size: 14px; }

        /* Tag picker */
        .lion-tag-wrapper { position: relative; }
        .lion-tag-selected {
            display: flex; flex-wrap: wrap; gap: 6px; min-height: 38px; padding: 6px 10px;
            border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; align-items: center;
        }
        .lion-tag-selected:empty::before {
            content: 'Clique nas tags abaixo para adicionar'; color: #94a3b8; font-size: 13px;
        }
        .lion-tag-pill {
            display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 12px;
            font-size: 12px; font-weight: 600; background: #f2a900; color: #1a1a2e;
            user-select: none; cursor: default;
        }
        .lion-tag-pill .lion-tag-remove {
            display: inline-flex; align-items: center; justify-content: center;
            width: 16px; height: 16px; border-radius: 50%; font-size: 11px; font-weight: 700;
            background: rgba(0,0,0,.15); color: #1a1a2e; cursor: pointer; line-height: 1;
        }
        .lion-tag-pill .lion-tag-remove:hover { background: rgba(0,0,0,.3); }
        .lion-tag-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .lion-tag-chip {
            display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 14px;
            font-size: 12px; font-weight: 500; cursor: pointer; transition: all .15s;
            background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;
        }
        .lion-tag-chip:hover { background: #e2e8f0; border-color: #94a3b8; }
        .lion-tag-chip.active { background: #fef3c7; color: #92400e; border-color: #f2a900; }

        /* Manual form input */
        .lion-manual-form-wrapper { display: none; margin-top: 8px; }
        .lion-manual-form-wrapper.active { display: flex; gap: 8px; align-items: center; }
        .lion-manual-form-wrapper input { flex: 1; }
        .lion-manual-form-wrapper button { white-space: nowrap; }

        /* Save bar */
        .lion-save-bar { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #e2e8f0; padding: 14px 24px; margin: 0 -24px -24px; border-radius: 0 0 10px 10px; display: flex; justify-content: flex-end; gap: 12px; }

        /* Responsive */
        @media (max-width: 782px) { .lion-grid-2 { grid-template-columns: 1fr; } .lion-wrap { margin: 10px; } }
    </style>

    <div class="lion-wrap">
        <!-- HEADER -->
        <div class="lion-header">
            <div class="lion-header-logo"><?php echo lion_get_logo_svg( '#f2a900' ); ?></div>
            <div class="lion-header-text">
                <h1>LionChat Lead Integrator <span class="lion-badge lion-badge-version">v<?php echo LION_VERSION; ?></span></h1>
                <p>Integre seus formulários com o LionChat — leads, tags e mensagens automáticas.</p>
            </div>
            <div class="lion-header-right">
                <span id="lion-conn-badge" class="lion-badge <?php echo $has_creds ? 'lion-badge-disconnected' : 'lion-badge-disconnected'; ?>">
                    <?php echo $has_creds ? 'Verificando...' : 'Desconectado'; ?>
                </span>
            </div>
        </div>

        <form method="post" action="options.php" id="lion-main-form">
            <?php settings_fields( 'lion_opts_group' ); ?>

            <!-- CONEXÃO -->
            <div class="lion-card">
                <h2 class="lion-card-title"><span class="dashicons dashicons-admin-network"></span> Conexão</h2>
                <p class="lion-card-desc">Configure as credenciais da sua conta LionChat.</p>
                <div class="lion-field">
                    <label>URL da Instância</label>
                    <input type="text" name="lion_url" id="lion_url" value="<?php echo esc_attr( get_option( 'lion_url', 'https://app.lionchat.com.br' ) ); ?>" placeholder="https://app.lionchat.com.br" />
                    <div class="hint">Endereço da sua instância LionChat.</div>
                </div>
                <div class="lion-grid-2">
                    <div class="lion-field">
                        <label>Token de Acesso</label>
                        <input type="password" name="lion_token" id="lion_token" value="<?php echo esc_attr( get_option( 'lion_token' ) ); ?>" placeholder="Seu token de API" />
                    </div>
                    <div class="lion-field">
                        <label>ID da Conta</label>
                        <input type="text" name="lion_acc" id="lion_acc" value="<?php echo esc_attr( get_option( 'lion_acc' ) ); ?>" placeholder="Ex: 1" />
                    </div>
                </div>
                <button type="button" id="lion-test-conn" class="lion-btn lion-btn-secondary">
                    <span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;"></span>
                    Testar Conexão
                </button>
                <span id="lion-conn-status" class="lion-inline-status"></span>
            </div>

            <!-- CAIXAS DE ENTRADA -->
            <div class="lion-card">
                <h2 class="lion-card-title"><span class="dashicons dashicons-format-chat"></span> Caixas de Entrada</h2>
                <p class="lion-card-desc">Selecione as caixas de entrada para receber os leads.</p>
                <div class="lion-grid-2">
                    <div class="lion-field">
                        <label>Inbox de Aviso (relatório do lead)</label>
                        <select name="lion_inbox" id="lion_inbox">
                            <option value="">Carregando...</option>
                            <?php if ( $saved_inbox ) : ?><option value="<?php echo esc_attr( $saved_inbox ); ?>" selected>ID: <?php echo esc_html( $saved_inbox ); ?> (salvo)</option><?php endif; ?>
                        </select>
                        <div class="hint">Onde o relatório completo do lead será enviado.</div>
                    </div>
                    <div class="lion-field">
                        <label>Inbox WhatsApp (mensagem automática)</label>
                        <select name="lion_outbox" id="lion_outbox">
                            <option value="">Carregando...</option>
                            <?php if ( $saved_outbox ) : ?><option value="<?php echo esc_attr( $saved_outbox ); ?>" selected>ID: <?php echo esc_html( $saved_outbox ); ?> (salvo)</option><?php endif; ?>
                        </select>
                        <div class="hint">Inbox WhatsApp para a mensagem de boas-vindas.</div>
                    </div>
                </div>
                <button type="button" id="lion-refresh-inboxes" class="lion-btn lion-btn-secondary">
                    <span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;"></span>
                    Atualizar Inboxes
                </button>
                <span id="lion-inbox-status" class="lion-inline-status"></span>
            </div>

            <!-- LIONTRACK -->
            <div class="lion-card">
                <h2 class="lion-card-title"><span class="dashicons dashicons-chart-area"></span> LionTrack — Rastreamento de Visitantes</h2>
                <p class="lion-card-desc">Rastreie automaticamente o comportamento dos visitantes no site: páginas visitadas, tempo em cada página, origem da visita e presença online em tempo real.</p>
                <div class="lion-field">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="lion_liontrack_enabled" value="1" <?php checked( get_option( 'lion_liontrack_enabled' ), '1' ); ?> style="width: 18px; height: 18px;" />
                        <span>Ativar rastreamento de visitantes</span>
                    </label>
                    <div class="hint">Quando ativado, o script LionTrack será carregado automaticamente em todas as páginas do site. Os dados de navegação aparecem no painel lateral da conversa no LionChat. A conta precisa ter o módulo LionTrack liberado.</div>
                </div>
            </div>

            <!-- REGRAS -->
            <div class="lion-card">
                <h2 class="lion-card-title"><span class="dashicons dashicons-editor-table"></span> Regras por Formulário</h2>
                <p class="lion-card-desc">Vincule cada formulário a uma tag e escolha a ação. <strong>WhatsApp QR (WAHA)</strong>: resposta pronta ou automação. <strong>WhatsApp Cloud API</strong>: template aprovado pela Meta ou automação.</p>

                <div style="margin-bottom: 16px; display: flex; gap: 8px; align-items: center;">
                    <button type="button" id="lion-refresh-data" class="lion-btn lion-btn-secondary">
                        <span class="dashicons dashicons-cloud" style="font-size:16px;width:16px;height:16px;"></span>
                        Atualizar Dados
                    </button>
                    <span id="lion-data-status" class="lion-inline-status"></span>
                </div>

                <div id="lion-rules-container">
                    <?php if ( ! empty( $rules ) ) : foreach ( $rules as $idx => $rule ) : ?>
                        <div class="lion-rule" data-idx="<?php echo $idx; ?>">
                            <div class="lion-rule-header">
                                <span class="lion-rule-num"><?php echo $idx + 1; ?></span>
                                <button type="button" class="lion-btn lion-btn-danger lion-remove-rule" style="padding:5px 12px;font-size:12px;">Remover</button>
                            </div>
                            <div class="lion-grid-2">
                                <div class="lion-field">
                                    <label>Formulário</label>
                                    <select name="lion_custom_rules[<?php echo $idx; ?>][name]" class="lion-form-select">
                                        <option value="<?php echo esc_attr( $rule['name'] ?? '' ); ?>"><?php echo esc_html( $rule['name'] ?? '' ); ?></option>
                                    </select>
                                    <div class="lion-manual-form-wrapper">
                                        <input type="text" class="lion-manual-form-input" placeholder="Nome do formulário" />
                                        <button type="button" class="lion-btn lion-btn-secondary lion-manual-form-ok" style="padding:6px 14px;font-size:12px;">OK</button>
                                        <button type="button" class="lion-btn lion-btn-secondary lion-manual-form-cancel" style="padding:6px 10px;font-size:12px;">✕</button>
                                    </div>
                                </div>
                                <div class="lion-field lion-tag-wrapper">
                                    <label>Tag(s)</label>
                                    <input type="hidden" name="lion_custom_rules[<?php echo $idx; ?>][tag]" class="lion-tag-input" value="<?php echo esc_attr( $rule['tag'] ?? '' ); ?>" />
                                    <div class="lion-tag-selected"></div>
                                    <div class="lion-tag-chips"></div>
                                    <div class="hint">Clique nas tags para adicionar ou remover.</div>
                                </div>
                            </div>

                            <?php $action_type = $rule['action_type'] ?? 'canned'; ?>
                            <input type="hidden" name="lion_custom_rules[<?php echo $idx; ?>][action_type]" class="lion-action-type-input" value="<?php echo esc_attr( $action_type ); ?>" />

                            <div class="lion-action-toggle">
                                <input type="radio" id="act_canned_<?php echo $idx; ?>" name="_act_<?php echo $idx; ?>" value="canned" class="lion-action-radio" <?php echo $action_type === 'canned' ? 'checked' : ''; ?>>
                                <label for="act_canned_<?php echo $idx; ?>"><span class="dashicons dashicons-format-quote" style="font-size:16px;width:16px;height:16px;"></span> Resposta Pronta</label>
                                <input type="radio" id="act_auto_<?php echo $idx; ?>" name="_act_<?php echo $idx; ?>" value="automation" class="lion-action-radio" <?php echo $action_type === 'automation' ? 'checked' : ''; ?>>
                                <label for="act_auto_<?php echo $idx; ?>"><span class="dashicons dashicons-randomize" style="font-size:16px;width:16px;height:16px;"></span> Automação Webhook</label>
                            </div>

                            <div class="lion-action-panel lion-panel-canned" <?php echo $action_type !== 'canned' ? 'style="display:none"' : ''; ?>>
                                <div class="lion-field">
                                    <label>Resposta Pronta</label>
                                    <select name="lion_custom_rules[<?php echo $idx; ?>][canned_id]" class="lion-canned-select">
                                        <?php $cid = $rule['canned_id'] ?? ''; ?>
                                        <option value="<?php echo esc_attr( $cid ); ?>"><?php echo $cid ? "ID: $cid (salvo)" : '-- Selecione --'; ?></option>
                                    </select>
                                    <div class="lion-canned-preview"></div>
                                </div>
                            </div>

                            <div class="lion-action-panel lion-panel-automation" <?php echo $action_type !== 'automation' ? 'style="display:none"' : ''; ?>>
                                <div class="lion-field">
                                    <label>Automação</label>
                                    <select name="lion_custom_rules[<?php echo $idx; ?>][automation_id]" class="lion-automation-select">
                                        <?php $aid = $rule['automation_id'] ?? ''; ?>
                                        <option value="<?php echo esc_attr( $aid ); ?>"><?php echo $aid ? "ID: $aid (salvo)" : '-- Selecione --'; ?></option>
                                    </select>
                                    <div class="lion-inbox-warn"><span class="dashicons dashicons-warning"></span> <span class="warn-text"></span></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; else : ?>
                        <div class="lion-empty" id="lion-empty-state">
                            <span class="dashicons dashicons-welcome-add-page"></span>
                            <p>Nenhuma regra configurada.<br>Clique em "Adicionar Regra" para começar.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <button type="button" id="lion-add-rule" class="lion-btn lion-btn-secondary" style="margin-top: 4px;">
                    <span class="dashicons dashicons-plus-alt2" style="font-size:16px;width:16px;height:16px;"></span>
                    Adicionar Regra
                </button>

                <div class="lion-save-bar">
                    <button type="submit" class="lion-btn lion-btn-primary" style="padding: 11px 28px; font-size: 14px;">
                        <span class="dashicons dashicons-saved" style="font-size:18px;width:18px;height:18px;"></span>
                        Salvar Configurações
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
    (function() {
        const NONCE = '<?php echo esc_js( $nonce ); ?>';
        const AJAX  = '<?php echo esc_js( admin_url( "admin-ajax.php" ) ); ?>';
        const SAVED_INBOX  = '<?php echo esc_js( $saved_inbox ); ?>';
        const SAVED_OUTBOX = '<?php echo esc_js( $saved_outbox ); ?>';
        const HAS_CREDS    = <?php echo $has_creds ? 'true' : 'false'; ?>;

        let cachedForms = [], cachedTags = [], cachedCanned = [], cachedAutomations = [], cachedInboxes = [];

        // ---- DOM refs ----
        const container    = document.getElementById('lion-rules-container');
        const outboxSel    = document.getElementById('lion_outbox');
        const inboxSel     = document.getElementById('lion_inbox');

        // ---- Helpers ----
        function getLiveCreds() {
            return {
                live_url:   document.getElementById('lion_url').value,
                live_acc:   document.getElementById('lion_acc').value,
                live_token: document.getElementById('lion_token').value,
            };
        }

        function post(action, extra) {
            var fd = new FormData();
            fd.append('action', action);
            fd.append('nonce', NONCE);
            // Sempre envia credenciais atuais do formulário (mesmo que não salvas)
            var creds = getLiveCreds();
            fd.append('live_url', creds.live_url);
            fd.append('live_acc', creds.live_acc);
            fd.append('live_token', creds.live_token);
            if (extra) Object.keys(extra).forEach(function(k) { fd.append(k, extra[k]); });
            return fetch(AJAX, { method: 'POST', body: fd }).then(function(r) { return r.json(); });
        }

        function inlineStatus(el, msg, type) {
            const icons = { ok: 'green', err: 'red', loading: 'yellow' };
            el.className = 'lion-inline-status ' + type;
            el.innerHTML = '<span class="lion-dot ' + icons[type] + '"></span> ' + msg;
            if (type !== 'loading') setTimeout(function() { if (el.className.indexOf(type) !== -1) el.innerHTML = ''; }, 6000);
        }

        function setBadge(ok) {
            const b = document.getElementById('lion-conn-badge');
            b.className = 'lion-badge ' + (ok ? 'lion-badge-connected' : 'lion-badge-disconnected');
            b.textContent = ok ? 'Conectado' : 'Desconectado';
        }

        // ---- Get selected outbox info ----
        function getSelectedOutbox() {
            if (!outboxSel.value) return null;
            return cachedInboxes.find(function(ib) { return String(ib.id) === String(outboxSel.value); }) || null;
        }

        function isCloudApiOutbox() {
            var ob = getSelectedOutbox();
            return !!(ob && ob.channel_type === 'Channel::Whatsapp');
        }

        // ---- Get inbox channel_type for an automation's inbox_id ----
        function getInboxChannelType(inboxId) {
            if (!inboxId) return null;
            var ib = cachedInboxes.find(function(x) { return String(x.id) === String(inboxId); });
            return ib ? ib.channel_type : null;
        }

        // ============================================================
        // TEST CONNECTION (faz tudo: testa + carrega inboxes + dados)
        // ============================================================
        document.getElementById('lion-test-conn').addEventListener('click', testConnection);

        function testConnection() {
            var btn = document.getElementById('lion-test-conn');
            var st  = document.getElementById('lion-conn-status');
            var inboxSt = document.getElementById('lion-inbox-status');
            var dataSt  = document.getElementById('lion-data-status');
            btn.disabled = true;
            inlineStatus(st, 'Testando...', 'loading');
            inlineStatus(inboxSt, 'Carregando...', 'loading');
            inlineStatus(dataSt, 'Carregando...', 'loading');
            post('lion_test_connection', {
                url: document.getElementById('lion_url').value,
                acc: document.getElementById('lion_acc').value,
                token: document.getElementById('lion_token').value,
            }).then(function(res) {
                btn.disabled = false;
                if (res.success) {
                    inlineStatus(st, res.data.message + ' (' + res.data.inbox_count + ' inboxes)', 'ok');
                    setBadge(true);
                    // Load inboxes first, then rule data (sequencially to avoid race)
                    loadInboxes(false).then(function() {
                        return loadRuleData(false);
                    }).then(function() {
                        // After BOTH are loaded, update toggles
                        updateAllRulesToggles();
                        inlineStatus(inboxSt, cachedInboxes.length + ' inbox(es)', 'ok');
                        var parts = [];
                        if (cachedTags.length) parts.push(cachedTags.length + ' tag(s)');
                        if (cachedCanned.length) parts.push(cachedCanned.length + ' resposta(s)');
                        if (cachedForms.length) parts.push(cachedForms.length + ' formulário(s)');
                        if (cachedAutomations.length) parts.push(cachedAutomations.length + ' automação(ões)');
                        inlineStatus(dataSt, parts.length ? parts.join(', ') : 'Nenhum dado', parts.length ? 'ok' : 'err');
                    });
                } else {
                    inlineStatus(st, res.data, 'err');
                    inlineStatus(inboxSt, '', 'err');
                    inlineStatus(dataSt, '', 'err');
                    setBadge(false);
                }
            }).catch(function() {
                btn.disabled = false;
                inlineStatus(st, 'Erro de rede.', 'err');
                setBadge(false);
            });
        }

        // ============================================================
        // LOAD INBOXES (retorna Promise)
        // ============================================================
        document.getElementById('lion-refresh-inboxes').addEventListener('click', function() {
            var st = document.getElementById('lion-inbox-status');
            inlineStatus(st, 'Carregando...', 'loading');
            loadInboxes(false).then(function() {
                updateAllRulesToggles();
                inlineStatus(st, cachedInboxes.length + ' inbox(es) carregada(s)', 'ok');
            });
        });

        function loadInboxes(showStatus) {
            return post('lion_fetch_inboxes').then(function(res) {
                if (!res.success) return;
                cachedInboxes = res.data;
                [['lion_inbox', SAVED_INBOX], ['lion_outbox', SAVED_OUTBOX]].forEach(function(pair) {
                    var id = pair[0], saved = pair[1];
                    var sel = document.getElementById(id);
                    sel.innerHTML = '<option value="">-- Selecione --</option>';
                    cachedInboxes.forEach(function(ib) {
                        var opt = new Option(ib.name + ' (' + ib.type + ')', ib.id);
                        opt.dataset.channelType = ib.channel_type || '';
                        if (String(ib.id) === String(saved)) opt.selected = true;
                        sel.add(opt);
                    });
                });
            });
        }

        // ============================================================
        // LOAD RULE DATA (retorna Promise)
        // ============================================================
        document.getElementById('lion-refresh-data').addEventListener('click', function() {
            var st = document.getElementById('lion-data-status');
            inlineStatus(st, 'Carregando...', 'loading');
            loadRuleData(false).then(function() {
                updateAllRulesToggles();
                var parts = [];
                if (cachedTags.length) parts.push(cachedTags.length + ' tag(s)');
                if (cachedCanned.length) parts.push(cachedCanned.length + ' resposta(s)');
                if (cachedForms.length) parts.push(cachedForms.length + ' formulário(s)');
                if (cachedAutomations.length) parts.push(cachedAutomations.length + ' automação(ões)');
                inlineStatus(st, parts.length ? parts.join(', ') : 'Nenhum dado encontrado', parts.length ? 'ok' : 'err');
            });
        });

        function loadRuleData(showStatus) {
            return Promise.all([
                post('lion_fetch_labels'),
                post('lion_fetch_canned'),
                post('lion_fetch_forms'),
                post('lion_fetch_automations'),
            ]).then(function(results) {
                var lr = results[0], cr = results[1], fr = results[2], ar = results[3];
                if (lr.success) cachedTags = lr.data;
                if (cr.success) cachedCanned = cr.data;
                if (fr.success) cachedForms = fr.data;
                if (ar.success) cachedAutomations = ar.data;

                // Populate all selects in existing rules
                populateAllTagChips();
                document.querySelectorAll('.lion-canned-select').forEach(populateCanned);
                document.querySelectorAll('.lion-form-select').forEach(populateForms);
                document.querySelectorAll('.lion-automation-select').forEach(function(sel) {
                    populateAutomations(sel);
                });
            });
        }

        // ============================================================
        // POPULATE HELPERS
        // ============================================================
        function populateAllTagChips() {
            document.querySelectorAll('.lion-tag-wrapper').forEach(function(wrapper) {
                renderTagUI(wrapper);
            });
        }

        function renderTagUI(wrapper) {
            var input = wrapper.querySelector('.lion-tag-input');
            var selectedArea = wrapper.querySelector('.lion-tag-selected');
            var chipsArea = wrapper.querySelector('.lion-tag-chips');
            if (!input || !selectedArea || !chipsArea) return;

            var currentTags = (input.value || '').split(',').map(function(t) { return t.trim(); }).filter(Boolean);

            // Render selected pills (area de cima — badges fechados)
            selectedArea.innerHTML = '';
            currentTags.forEach(function(tag) {
                var pill = document.createElement('span');
                pill.className = 'lion-tag-pill';
                pill.innerHTML = tag + ' <span class="lion-tag-remove" data-tag="' + tag + '">&times;</span>';
                selectedArea.appendChild(pill);
            });

            // Click to remove pill
            selectedArea.querySelectorAll('.lion-tag-remove').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    toggleTag(wrapper, btn.dataset.tag);
                });
            });

            // Render available chips (area de baixo — todas as tags)
            chipsArea.innerHTML = '';
            if (!cachedTags.length) {
                chipsArea.innerHTML = '<span style="font-size:12px;color:#94a3b8;">Nenhuma tag carregada. Clique em "Atualizar Dados".</span>';
                return;
            }
            var lowerCurrent = currentTags.map(function(t) { return t.toLowerCase(); });
            cachedTags.forEach(function(t) {
                var isActive = lowerCurrent.indexOf(t.title.toLowerCase()) !== -1;
                var chip = document.createElement('span');
                chip.className = 'lion-tag-chip' + (isActive ? ' active' : '');
                chip.textContent = (isActive ? '✓ ' : '+ ') + t.title;
                chip.addEventListener('click', function() {
                    toggleTag(wrapper, t.title);
                });
                chipsArea.appendChild(chip);
            });
        }

        function toggleTag(wrapper, tagTitle) {
            var input = wrapper.querySelector('.lion-tag-input');
            var tags = (input.value || '').split(',').map(function(t) { return t.trim(); }).filter(Boolean);
            var lowerTags = tags.map(function(t) { return t.toLowerCase(); });
            var idx = lowerTags.indexOf(tagTitle.toLowerCase());
            if (idx !== -1) {
                tags.splice(idx, 1);
            } else {
                tags.push(tagTitle);
            }
            input.value = tags.join(', ');
            renderTagUI(wrapper);
        }

        function populateCanned(sel) {
            var cur = sel.value;
            sel.innerHTML = '<option value="">-- Selecione --</option>';
            cachedCanned.forEach(function(c) {
                sel.add(new Option('/' + c.short_code + ' — ' + c.content, c.id, false, String(c.id) === String(cur)));
            });
            updateCannedPreview(sel);
        }

        function populateForms(sel) {
            var cur = sel.value;
            sel.innerHTML = '<option value="">-- Selecione --</option>';
            cachedForms.forEach(function(f) { sel.add(new Option(f, f, false, f === cur)); });
            sel.add(new Option('+ Digitar manualmente...', '__custom__'));
        }

        function populateAutomations(sel) {
            var cur = sel.value;
            var isCloud = isCloudApiOutbox();
            sel.innerHTML = '<option value="">-- Selecione --</option>';
            cachedAutomations.forEach(function(a) {
                if (a.inbox_id) {
                    var autoChannelType = getInboxChannelType(a.inbox_id);
                    if (isCloud && autoChannelType !== 'Channel::Whatsapp') return;
                    if (!isCloud && autoChannelType === 'Channel::Whatsapp') return;
                }
                var opt = new Option(a.name + ' (' + a.event + ')', a.id, false, String(a.id) === String(cur));
                opt.dataset.inboxId = a.inbox_id || '';
                sel.add(opt);
            });
            checkInboxMismatch(sel);
        }

        function updateCannedPreview(sel) {
            var preview = sel.closest('.lion-field');
            preview = preview ? preview.querySelector('.lion-canned-preview') : null;
            if (!preview) return;
            var id = sel.value;
            var c = cachedCanned.find(function(x) { return String(x.id) === String(id); });
            if (c && c.blocks > 0) {
                preview.innerHTML = '<span class="dashicons dashicons-info-outline"></span> ' + c.blocks + ' bloco(s): ' + c.summary;
            } else if (c) {
                preview.innerHTML = '<span class="dashicons dashicons-info-outline"></span> ' + c.summary;
            } else {
                preview.innerHTML = '';
            }
        }

        function checkInboxMismatch(sel) {
            var panel = sel.closest('.lion-panel-automation');
            var warn = panel ? panel.querySelector('.lion-inbox-warn') : null;
            if (!warn) return;
            var opt = sel.options[sel.selectedIndex];
            var autoInbox = opt && opt.dataset ? opt.dataset.inboxId : '';
            var selectedOutbox = outboxSel.value;
            if (autoInbox && selectedOutbox && autoInbox !== selectedOutbox) {
                warn.style.display = 'flex';
                warn.querySelector('.warn-text').textContent = 'A inbox desta automação (ID: ' + autoInbox + ') é diferente da inbox WhatsApp selecionada (ID: ' + selectedOutbox + ').';
            } else {
                warn.style.display = 'none';
            }
        }

        // ============================================================
        // TOGGLE: ATUALIZAR TIPO DE AÇÃO POR REGRA
        // ============================================================
        function updateAllRulesToggles() {
            var isCloud = isCloudApiOutbox();
            document.querySelectorAll('.lion-rule').forEach(function(rule) {
                updateRuleToggle(rule, isCloud);
            });
            // Re-populate automations (filtro por tipo de inbox)
            document.querySelectorAll('.lion-automation-select').forEach(function(sel) {
                populateAutomations(sel);
            });
        }

        function updateRuleToggle(rule, isCloud) {
            var actionType = rule.querySelector('.lion-action-type-input');
            var toggleDiv = rule.querySelector('.lion-action-toggle');
            if (!actionType || !toggleDiv) return;

            var nameAttr = actionType.name || '';
            var m = nameAttr.match(/\[(\d+)\]/);
            var idx = m ? m[1] : '0';

            // Get/create panels
            var panelCanned = rule.querySelector('.lion-panel-canned');
            var panelAuto = rule.querySelector('.lion-panel-automation');
            var panelTemplate = rule.querySelector('.lion-panel-template');

            if (isCloud) {
                // ---- CLOUD API: Template WhatsApp | Automação ----
                var tplChecked = (actionType.value !== 'automation');
                var autoChecked = (actionType.value === 'automation');

                toggleDiv.innerHTML = ''
                    + '<input type="radio" id="act_template_' + idx + '" name="_act_' + idx + '" value="template" class="lion-action-radio"' + (tplChecked ? ' checked' : '') + '>'
                    + '<label for="act_template_' + idx + '"><span class="dashicons dashicons-editor-paste-word" style="font-size:16px;width:16px;height:16px;"></span> Template WhatsApp <span class="lion-cloud-badge">API</span></label>'
                    + '<input type="radio" id="act_auto_' + idx + '" name="_act_' + idx + '" value="automation" class="lion-action-radio"' + (autoChecked ? ' checked' : '') + '>'
                    + '<label for="act_auto_' + idx + '"><span class="dashicons dashicons-randomize" style="font-size:16px;width:16px;height:16px;"></span> Automação Webhook</label>';

                // Hide canned panel
                if (panelCanned) panelCanned.style.display = 'none';

                // Create template panel if needed
                if (!panelTemplate) {
                    panelTemplate = document.createElement('div');
                    panelTemplate.className = 'lion-action-panel lion-panel-template';
                    panelTemplate.innerHTML = ''
                        + '<div class="lion-field">'
                        + '<label>Template WhatsApp</label>'
                        + '<select name="lion_custom_rules[' + idx + '][template_name]" class="lion-template-select"><option value="">-- Selecione um template --</option></select>'
                        + '<input type="hidden" name="lion_custom_rules[' + idx + '][template_language]" class="lion-tpl-language" value="pt_BR" />'
                        + '<div class="lion-tpl-info"><span class="dashicons dashicons-info-outline"></span> Apenas templates aprovados pela Meta são exibidos.</div>'
                        + '<div class="lion-tpl-preview" style="display:none"></div>'
                        + '<div class="lion-tpl-vars"></div>'
                        + '</div>';
                    if (panelAuto) {
                        rule.insertBefore(panelTemplate, panelAuto);
                    } else {
                        rule.appendChild(panelTemplate);
                    }
                }

                // Populate templates
                var ob = getSelectedOutbox();
                var tplSel = panelTemplate.querySelector('.lion-template-select');
                var tplInfo = panelTemplate.querySelector('.lion-tpl-info');
                if (ob && ob.templates && ob.templates.length) {
                    populateTemplates(tplSel, ob.templates, rule);
                    if (tplInfo) tplInfo.innerHTML = '<span class="dashicons dashicons-info-outline"></span> ' + ob.templates.length + ' template(s) aprovado(s) encontrado(s).';
                } else {
                    tplSel.innerHTML = '<option value="">-- Nenhum template encontrado --</option>';
                    if (tplInfo) tplInfo.innerHTML = '<span class="dashicons dashicons-warning"></span> Nenhum template aprovado encontrado. Verifique se a inbox Cloud API tem templates aprovados pela Meta.';
                }

                // Set action type + visibility
                if (tplChecked) actionType.value = 'template';
                panelTemplate.style.display = tplChecked ? '' : 'none';
                if (panelAuto) panelAuto.style.display = autoChecked ? '' : 'none';

            } else {
                // ---- WAHA / OUTRO: Resposta Pronta | Automação ----
                var cannedChecked = (actionType.value !== 'automation');
                var autoChecked2 = (actionType.value === 'automation');

                toggleDiv.innerHTML = ''
                    + '<input type="radio" id="act_canned_' + idx + '" name="_act_' + idx + '" value="canned" class="lion-action-radio"' + (cannedChecked ? ' checked' : '') + '>'
                    + '<label for="act_canned_' + idx + '"><span class="dashicons dashicons-format-quote" style="font-size:16px;width:16px;height:16px;"></span> Resposta Pronta <span class="lion-waha-badge">QR</span></label>'
                    + '<input type="radio" id="act_auto_' + idx + '" name="_act_' + idx + '" value="automation" class="lion-action-radio"' + (autoChecked2 ? ' checked' : '') + '>'
                    + '<label for="act_auto_' + idx + '"><span class="dashicons dashicons-randomize" style="font-size:16px;width:16px;height:16px;"></span> Automação Webhook</label>';

                // Hide template panel
                if (panelTemplate) panelTemplate.style.display = 'none';

                // Set action type + visibility
                if (cannedChecked) actionType.value = 'canned';
                if (panelCanned) panelCanned.style.display = cannedChecked ? '' : 'none';
                if (panelAuto) panelAuto.style.display = autoChecked2 ? '' : 'none';
            }
        }

        // ============================================================
        // TEMPLATE HELPERS
        // ============================================================
        function populateTemplates(sel, templates, rule) {
            var savedName = sel.value || '';
            sel.innerHTML = '<option value="">-- Selecione um template --</option>';
            templates.forEach(function(tpl) {
                var label = tpl.name + ' (' + tpl.language + ')' + (tpl.body_vars > 0 ? ' — ' + tpl.body_vars + ' variável(is)' : '');
                var opt = new Option(label, tpl.name, false, tpl.name === savedName);
                opt.dataset.body = tpl.body || '';
                opt.dataset.bodyVars = tpl.body_vars || 0;
                opt.dataset.headerVars = tpl.header_vars || 0;
                opt.dataset.language = tpl.language || 'pt_BR';
                sel.add(opt);
            });
            updateTemplatePreview(sel, rule);
        }

        function updateTemplatePreview(sel, rule) {
            var panel = sel.closest('.lion-panel-template');
            if (!panel) return;
            var preview = panel.querySelector('.lion-tpl-preview');
            var varsDiv = panel.querySelector('.lion-tpl-vars');
            var opt = sel.options[sel.selectedIndex];

            if (!opt || !opt.value) {
                preview.style.display = 'none';
                varsDiv.innerHTML = '';
                return;
            }

            var body = opt.dataset.body || '';
            var bodyVars = parseInt(opt.dataset.bodyVars) || 0;
            var headerVars = parseInt(opt.dataset.headerVars) || 0;
            var language = opt.dataset.language || 'pt_BR';
            var nameAttr = rule.querySelector('[name*="[action_type]"]');
            var mi = nameAttr ? nameAttr.name.match(/\[(\d+)\]/) : null;
            var idx = mi ? mi[1] : '0';

            // Set language hidden input
            var langInput = panel.querySelector('.lion-tpl-language');
            if (langInput) langInput.value = language;

            // Show body preview with highlighted vars
            preview.innerHTML = body.replace(/\{\{(\d+)\}\}/g, '<span class="tpl-var">{{$1}}</span>');
            preview.style.display = body ? '' : 'none';

            // Variable mapping fields
            var varOptions = [
                { value: '', label: '-- Selecione --' },
                { value: 'nome', label: 'Nome do lead' },
                { value: 'email', label: 'E-mail' },
                { value: 'telefone', label: 'Telefone' },
                { value: 'formulario', label: 'Nome do formulário' },
                { value: 'custom', label: 'Texto fixo (digitar)' },
            ];

            var html = '';
            if (headerVars > 0) {
                html += '<div style="font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;">Variáveis do cabeçalho:</div>';
                for (var i = 1; i <= headerVars; i++) html += buildVarField(idx, 'header', i, varOptions);
            }
            if (bodyVars > 0) {
                html += '<div style="font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;margin-top:8px;">Variáveis do corpo:</div>';
                for (var j = 1; j <= bodyVars; j++) html += buildVarField(idx, 'body', j, varOptions);
            }
            varsDiv.innerHTML = html;
        }

        function buildVarField(idx, section, num, options) {
            var name = 'lion_custom_rules[' + idx + '][tpl_var_' + section + '_' + num + ']';
            var customName = 'lion_custom_rules[' + idx + '][tpl_var_' + section + '_' + num + '_custom]';
            var html = '<div class="lion-field" style="margin-bottom:8px;">';
            html += '<label>{{' + num + '}} — ' + (section === 'header' ? 'Cabeçalho' : 'Corpo') + '</label>';
            html += '<div style="display:flex;gap:8px;">';
            html += '<select name="' + name + '" class="lion-tpl-var-select" style="flex:1;">';
            options.forEach(function(o) { html += '<option value="' + o.value + '">' + o.label + '</option>'; });
            html += '</select>';
            html += '<input type="text" name="' + customName + '" class="lion-tpl-var-custom" placeholder="Digite o texto fixo" style="flex:1;display:none;" />';
            html += '</div></div>';
            return html;
        }

        // ============================================================
        // EVENT DELEGATION (um único handler master)
        // ============================================================
        container.addEventListener('change', function(e) {
            var target = e.target;

            // Form custom — mostra input inline em vez de prompt()
            if (target.classList.contains('lion-form-select') && target.value === '__custom__') {
                target.value = '';
                var wrapper = target.closest('.lion-field').querySelector('.lion-manual-form-wrapper');
                if (wrapper) {
                    wrapper.classList.add('active');
                    wrapper.querySelector('.lion-manual-form-input').focus();
                }
            }

            // Canned preview
            if (target.classList.contains('lion-canned-select')) updateCannedPreview(target);

            // Automation inbox check
            if (target.classList.contains('lion-automation-select')) checkInboxMismatch(target);

            // Action type toggle (radio buttons)
            if (target.classList.contains('lion-action-radio')) {
                var rule = target.closest('.lion-rule');
                var val = target.value;
                rule.querySelector('.lion-action-type-input').value = val;
                var pc = rule.querySelector('.lion-panel-canned');
                var pa = rule.querySelector('.lion-panel-automation');
                var pt = rule.querySelector('.lion-panel-template');
                if (pc) pc.style.display = (val === 'canned') ? '' : 'none';
                if (pa) pa.style.display = (val === 'automation') ? '' : 'none';
                if (pt) pt.style.display = (val === 'template') ? '' : 'none';
            }

            // Template select change
            if (target.classList.contains('lion-template-select')) {
                updateTemplatePreview(target, target.closest('.lion-rule'));
            }

            // Template var select (custom text toggle)
            if (target.classList.contains('lion-tpl-var-select')) {
                var customInput = target.closest('div').querySelector('.lion-tpl-var-custom');
                if (customInput) customInput.style.display = (target.value === 'custom') ? '' : 'none';
            }
        });

        // Click delegation: remove rule + manual form OK/Cancel
        container.addEventListener('click', function(e) {
            if (e.target.classList.contains('lion-remove-rule')) {
                e.target.closest('.lion-rule').remove();
                renumberRules();
            }
            // Manual form — OK
            if (e.target.classList.contains('lion-manual-form-ok')) {
                var wrapper = e.target.closest('.lion-manual-form-wrapper');
                var input = wrapper.querySelector('.lion-manual-form-input');
                var sel = wrapper.closest('.lion-field').querySelector('.lion-form-select');
                var formName = (input.value || '').trim();
                if (formName) {
                    var opt = new Option(formName, formName, true, true);
                    sel.insertBefore(opt, sel.lastElementChild);
                    sel.value = formName;
                }
                input.value = '';
                wrapper.classList.remove('active');
            }
            // Manual form — Cancel
            if (e.target.classList.contains('lion-manual-form-cancel')) {
                var wrapper2 = e.target.closest('.lion-manual-form-wrapper');
                wrapper2.querySelector('.lion-manual-form-input').value = '';
                wrapper2.classList.remove('active');
            }
        });

        // Manual form — Enter key
        container.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.classList.contains('lion-manual-form-input')) {
                e.preventDefault();
                e.target.closest('.lion-manual-form-wrapper').querySelector('.lion-manual-form-ok').click();
            }
        });

        // ============================================================
        // ADD RULE
        // ============================================================
        document.getElementById('lion-add-rule').addEventListener('click', function() {
            var empty = document.getElementById('lion-empty-state');
            if (empty) empty.remove();
            var idx = container.querySelectorAll('.lion-rule').length;
            var div = document.createElement('div');
            div.className = 'lion-rule';
            div.innerHTML = ''
                + '<div class="lion-rule-header">'
                + '<span class="lion-rule-num">' + (idx + 1) + '</span>'
                + '<button type="button" class="lion-btn lion-btn-danger lion-remove-rule" style="padding:5px 12px;font-size:12px;">Remover</button>'
                + '</div>'
                + '<div class="lion-grid-2">'
                + '<div class="lion-field"><label>Formulário</label><select name="lion_custom_rules[' + idx + '][name]" class="lion-form-select"><option value="">-- Selecione --</option></select>'
                + '<div class="lion-manual-form-wrapper"><input type="text" class="lion-manual-form-input" placeholder="Nome do formulário" /><button type="button" class="lion-btn lion-btn-secondary lion-manual-form-ok" style="padding:6px 14px;font-size:12px;">OK</button><button type="button" class="lion-btn lion-btn-secondary lion-manual-form-cancel" style="padding:6px 10px;font-size:12px;">✕</button></div></div>'
                + '<div class="lion-field lion-tag-wrapper"><label>Tag(s)</label><input type="hidden" name="lion_custom_rules[' + idx + '][tag]" class="lion-tag-input" value="" /><div class="lion-tag-selected"></div><div class="lion-tag-chips"></div><div class="hint">Clique nas tags para adicionar ou remover.</div></div>'
                + '</div>'
                + '<input type="hidden" name="lion_custom_rules[' + idx + '][action_type]" class="lion-action-type-input" value="canned" />'
                + '<div class="lion-action-toggle"></div>'
                + '<div class="lion-action-panel lion-panel-canned">'
                + '<div class="lion-field"><label>Resposta Pronta</label><select name="lion_custom_rules[' + idx + '][canned_id]" class="lion-canned-select"><option value="">-- Selecione --</option></select><div class="lion-canned-preview"></div></div>'
                + '</div>'
                + '<div class="lion-action-panel lion-panel-automation" style="display:none">'
                + '<div class="lion-field"><label>Automação</label><select name="lion_custom_rules[' + idx + '][automation_id]" class="lion-automation-select"><option value="">-- Selecione --</option></select><div class="lion-inbox-warn"><span class="dashicons dashicons-warning"></span> <span class="warn-text"></span></div></div>'
                + '</div>';
            container.appendChild(div);

            // Populate with cached data
            if (cachedForms.length) populateForms(div.querySelector('.lion-form-select'));
            if (cachedCanned.length) populateCanned(div.querySelector('.lion-canned-select'));
            if (cachedAutomations.length) populateAutomations(div.querySelector('.lion-automation-select'));
            // Tag UI
            var tagWrapper = div.querySelector('.lion-tag-wrapper');
            if (tagWrapper) renderTagUI(tagWrapper);

            // Apply correct toggle based on outbox type
            updateRuleToggle(div, isCloudApiOutbox());
        });

        function renumberRules() {
            var rules = container.querySelectorAll('.lion-rule');
            rules.forEach(function(rule, i) {
                rule.querySelector('.lion-rule-num').textContent = i + 1;
                rule.querySelectorAll('[name]').forEach(function(input) {
                    input.name = input.name.replace(/\[\d+\]/, '[' + i + ']');
                });
                rule.querySelectorAll('.lion-action-radio').forEach(function(r) {
                    r.id = 'act_' + r.value + '_' + i;
                    r.name = '_act_' + i;
                });
                rule.querySelectorAll('.lion-action-toggle label').forEach(function(l) {
                    var forVal = l.getAttribute('for');
                    if (forVal) l.setAttribute('for', forVal.replace(/_\d+$/, '_' + i));
                });
            });
            if (!rules.length) {
                container.innerHTML = '<div class="lion-empty" id="lion-empty-state"><span class="dashicons dashicons-welcome-add-page"></span><p>Nenhuma regra configurada.<br>Clique em "Adicionar Regra" para começar.</p></div>';
            }
        }

        // ============================================================
        // OUTBOX CHANGE → atualiza tudo
        // ============================================================
        outboxSel.addEventListener('change', function() {
            updateAllRulesToggles();
        });

        // ============================================================
        // AUTO-LOAD ao abrir a página
        // ============================================================
        if (HAS_CREDS) {
            testConnection();
        }

    })();
    </script>
    <?php
}

// ============================================================
// PÁGINA DE LOGS
// ============================================================
function lion_render_logs_page() {
    if ( isset( $_POST['lion_clear_logs'] ) && check_admin_referer( 'lion_clear_logs_action' ) ) {
        update_option( 'lion_debug_logs', [] );
        echo '<div class="notice notice-success"><p>Logs limpos com sucesso.</p></div>';
    }
    $logs = array_reverse( get_option( 'lion_debug_logs', [] ) );
    ?>
    <style>
        .lion-wrap { max-width: 960px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .lion-header { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); padding: 24px 28px; border-radius: 12px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; }
        .lion-header h1 { margin: 0; font-size: 20px; font-weight: 700; color: #fff; }
        .lion-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .lion-log-box { background: #0f172a; color: #e2e8f0; padding: 20px; border-radius: 8px; height: 500px; overflow-y: auto; font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: 12px; line-height: 1.8; }
        .lion-log-box .log-line { padding: 2px 0; border-bottom: 1px solid rgba(255,255,255,.04); }
        .log-info { color: #60a5fa; } .log-sync { color: #a78bfa; } .log-aviso { color: #fbbf24; }
        .log-sucesso { color: #34d399; } .log-critico { color: #f87171; font-weight: bold; } .log-erro { color: #fb923c; }
        .lion-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid transparent; margin-top: 16px; }
        .lion-btn-danger { background: #fff; color: #dc2626; border-color: #fca5a5; }
        .lion-btn-danger:hover { background: #fef2f2; }
    </style>
    <div class="lion-wrap">
        <div class="lion-header">
            <?php echo lion_get_logo_svg( '#f2a900' ); ?>
            <h1>Logs do Sistema</h1>
        </div>
        <div class="lion-card">
            <div class="lion-log-box">
                <?php if ( empty( $logs ) ) : ?>
                    <div style="color:#64748b;text-align:center;padding-top:200px;">Nenhuma atividade registrada.</div>
                <?php else : foreach ( $logs as $log ) :
                    $cls = 'log-info';
                    if ( strpos($log,'[SYNC]') !== false ) $cls = 'log-sync';
                    elseif ( strpos($log,'[AVISO]') !== false ) $cls = 'log-aviso';
                    elseif ( strpos($log,'[SUCESSO]') !== false ) $cls = 'log-sucesso';
                    elseif ( strpos($log,'[CRITICO]') !== false ) $cls = 'log-critico';
                    elseif ( strpos($log,'[ERRO]') !== false ) $cls = 'log-erro';
                ?>
                    <div class="log-line <?php echo $cls; ?>"><?php echo esc_html( $log ); ?></div>
                <?php endforeach; endif; ?>
            </div>
            <form method="post">
                <?php wp_nonce_field( 'lion_clear_logs_action' ); ?>
                <input type="hidden" name="lion_clear_logs" value="1" />
                <button type="submit" class="lion-btn lion-btn-danger">
                    <span class="dashicons dashicons-trash" style="font-size:16px;width:16px;height:16px;"></span>
                    Limpar Logs
                </button>
            </form>
        </div>
    </div>
    <?php
}

// ============================================================
// HELPER: Normalizar telefone BR (padrão LionChat: +55DDDNNNNNNNNN)
// ============================================================
function lion_normalize_phone( $raw ) {
    // Remove tudo que não é dígito ou +
    $clean = preg_replace( '/[^\d+]/', '', $raw );

    // Se começa com +, separa o +
    $has_plus = ( strpos( $clean, '+' ) === 0 );
    $digits   = preg_replace( '/\D/', '', $clean );

    if ( empty( $digits ) ) return '';

    $len = strlen( $digits );

    // Já tem DDI completo (55 + DDD + número = 12 ou 13 dígitos)
    if ( $len === 13 && substr( $digits, 0, 2 ) === '55' ) {
        // +55 11 9XXXX XXXX (celular com 9) — OK
        return '+' . $digits;
    }
    if ( $len === 12 && substr( $digits, 0, 2 ) === '55' ) {
        // +55 11 XXXX XXXX (fixo 8 dígitos ou celular sem 9)
        $ddd    = substr( $digits, 2, 2 );
        $number = substr( $digits, 4 );
        // Se for celular (DDD 11-99) com 8 dígitos, adiciona o 9
        if ( strlen( $number ) === 8 && (int) $number[0] >= 6 ) {
            return '+55' . $ddd . '9' . $number;
        }
        return '+' . $digits;
    }

    // Sem DDI — assume Brasil
    if ( $len === 11 ) {
        // DDD + 9 + 8 dígitos (celular BR padrão)
        return '+55' . $digits;
    }
    if ( $len === 10 ) {
        // DDD + 8 dígitos (celular sem 9 ou fixo)
        $ddd    = substr( $digits, 0, 2 );
        $number = substr( $digits, 2 );
        // Se celular (começa com 6-9), adiciona 9
        if ( (int) $number[0] >= 6 ) {
            return '+55' . $ddd . '9' . $number;
        }
        // Fixo (começa com 2-5) — mantém
        return '+55' . $digits;
    }
    if ( $len === 9 ) {
        // Só o número sem DDD — não tem como saber o DDD, retorna vazio
        return '';
    }
    if ( $len === 8 ) {
        // Só o número sem DDD e sem 9 — impossível normalizar
        return '';
    }

    // DDI de outro país (>= 10 dígitos, não começa com 55)
    if ( $len >= 10 && substr( $digits, 0, 2 ) !== '55' ) {
        return '+' . $digits;
    }

    // Fallback: se tem + e >= 10 dígitos, aceita como está
    if ( $has_plus && $len >= 10 ) {
        return '+' . $digits;
    }

    return '';
}

// ============================================================
// HELPER: Buscar conversa existente ou criar nova
// ============================================================
function lion_find_or_create_conversation( $contact_id, $inbox_id, $custom_attributes = [] ) {
    // Busca conversas do contato
    $res = lion_api( 'GET', "/contacts/$contact_id/conversations" );

    if ( ! is_wp_error( $res ) && ! empty( $res['payload'] ) ) {
        foreach ( $res['payload'] as $conv ) {
            // Mesma inbox E status aberto ou pendente
            $conv_inbox  = $conv['inbox_id'] ?? null;
            $conv_status = $conv['status'] ?? '';
            if ( (int) $conv_inbox === $inbox_id && in_array( $conv_status, [ 'open', 'pending' ] ) ) {
                lion_log( "Conversa existente encontrada: #" . $conv['id'] . " (status: $conv_status)", 'INFO' );
                // Merge UTMs com custom_attributes existentes da conversa
                if ( ! empty($custom_attributes) ) {
                    $existing_attrs = $conv['custom_attributes'] ?? [];
                    $merged = array_merge( $existing_attrs, $custom_attributes );
                    lion_api( 'POST', "/conversations/" . $conv['id'] . "/custom_attributes", [ 'custom_attributes' => $merged ] );
                }
                return $conv['id'];
            }
        }
    }

    // Nenhuma conversa aberta nessa inbox — cria nova (ja com UTMs)
    $conv_payload = [
        'inbox_id'   => $inbox_id,
        'contact_id' => $contact_id,
        'status'     => 'open',
    ];
    if ( ! empty($custom_attributes) ) {
        $conv_payload['custom_attributes'] = $custom_attributes;
    }
    $create = lion_api( 'POST', '/conversations', $conv_payload );

    if ( ! is_wp_error( $create ) && ! empty( $create['id'] ) ) {
        lion_log( "Nova conversa criada: #" . $create['id'], 'SUCESSO' );
        return $create['id'];
    }

    lion_log( "Erro ao criar/buscar conversa para contato $contact_id na inbox $inbox_id", 'ERRO' );
    return null;
}

// ============================================================
// PROCESSAMENTO CENTRAL DE LEAD (reutilizado por todos os hooks)
// $form_name = nome do formulário
// $raw = array associativo [ 'Label' => 'valor', ... ]
// ============================================================
function lion_process_lead( $form_name, $raw ) {
    try {
        $rules = get_option( 'lion_custom_rules', [] );
        if ( empty( get_option('lion_url') ) || empty( get_option('lion_acc') ) || empty( get_option('lion_token') ) ) return;

        // Auto-detect
        $detected = get_option( 'lion_detected_forms', [] );
        if ( ! is_array($detected) ) $detected = [];
        if ( ! empty($form_name) && ! in_array($form_name, $detected) ) {
            $detected[] = $form_name;
            update_option( 'lion_detected_forms', $detected );
        }

        // Find rule
        $matched = null;
        if ( is_array($rules) ) {
            foreach ( $rules as $rule ) {
                if ( strtolower(trim($rule['name'] ?? '')) === strtolower(trim($form_name)) ) {
                    $matched = $rule;
                    break;
                }
            }
        }
        if ( ! $matched ) {
            lion_log( "Formulário '$form_name' sem regra configurada.", 'AVISO' );
            return;
        }

        // Extract fields
        $name = 'Lead Site'; $email = ''; $phone = ''; $extras = '';
        foreach ( $raw as $label => $value ) {
            $k = strtolower($label);
            if ( preg_match('/(nom|name|user|usu|client|pess|compl|full)/i', $k) && empty($email) && empty($phone) ) {
                $name = sanitize_text_field($value);
            } elseif ( strpos($k,'mail') !== false ) {
                $email = sanitize_email($value);
            } elseif ( preg_match('/(tel|cel|whats|fone|phone)/i', $k) ) {
                $phone = sanitize_text_field($value);
            } else {
                $extras .= "- **" . sanitize_text_field($label) . ":** " . sanitize_text_field($value) . "\n";
            }
        }

        // Normalize phone (padrão LionChat: +55DDDNNNNNNNNN)
        $phone_final = lion_normalize_phone( $phone );
        if ( empty( $phone_final ) ) {
            lion_log( "Telefone inválido para '$name': '$phone'", 'AVISO' );
            return;
        }

        lion_log( "Lead recebido: $name | $phone_final | Formulário: $form_name", 'INFO' );

        // 1. Find or create contact
        $search = lion_api( 'GET', '/contacts/search?q=' . urlencode($phone_final) );
        $contact_id = null;
        if ( ! is_wp_error($search) && ! empty($search['payload']) ) {
            foreach ( ($search['payload'] ?? []) as $ct ) {
                if ( ($ct['phone_number'] ?? '') === $phone_final ) {
                    $contact_id = $ct['id'];
                    break;
                }
            }
            if ( ! $contact_id && ! empty($search['payload'][0]['id']) ) {
                $contact_id = $search['payload'][0]['id'];
            }
        }
        if ( ! $contact_id ) {
            $contact_data = [ 'name' => $name, 'email' => $email, 'phone_number' => $phone_final ];
            if ( ! empty($extras) ) {
                $custom_attrs = [];
                foreach ( $raw as $label => $value ) {
                    $k = strtolower($label);
                    if ( preg_match('/(nom|name|user|usu|client|pess|compl|full)/i', $k) ) continue;
                    if ( strpos($k,'mail') !== false ) continue;
                    if ( preg_match('/(tel|cel|whats|fone|phone)/i', $k) ) continue;
                    $custom_attrs[ sanitize_title($label) ] = sanitize_text_field($value);
                }
                if ( ! empty($custom_attrs) ) {
                    $contact_data['custom_attributes'] = $custom_attrs;
                }
            }
            $create = lion_api( 'POST', '/contacts', $contact_data );
            if ( ! is_wp_error($create) ) $contact_id = $create['payload']['contact']['id'] ?? null;
            else { lion_log( "Erro ao criar contato: " . $create->get_error_message(), 'ERRO' ); return; }
        }
        if ( ! $contact_id ) { lion_log( "Contato não encontrado/criado para $phone_final", 'ERRO' ); return; }

        // 2. Capturar UTMs do cookie (salvos pelo JS no frontend)
        $utm_data = lion_get_utm_from_cookie();

        // 3. Report in API inbox
        $inbox_api = get_option('lion_inbox');
        if ( ! empty($inbox_api) ) {
            $report = "**Lead: $form_name**\n\n**Nome:** {$name}\n**Email:** {$email}\n**Telefone:** {$phone_final}\n\n**Dados adicionais:**\n{$extras}";
            $conv_payload = [
                'inbox_id' => (int)$inbox_api, 'contact_id' => $contact_id, 'status' => 'open',
                'message' => [ 'content' => $report, 'message_type' => 'incoming' ],
            ];
            if ( ! empty($utm_data) ) {
                $conv_payload['custom_attributes'] = $utm_data;
            }
            $api_conv = lion_api( 'POST', '/conversations', $conv_payload );
            if ( ! empty($utm_data) && ! is_wp_error($api_conv) ) {
                lion_log( "UTMs salvos na conversa API: " . implode(', ', array_keys($utm_data)), 'INFO' );
            }
        }

        // 4. WhatsApp conversation (UTMs passados direto — merge ou criacao com dados)
        $inbox_wa = get_option('lion_outbox');
        $conv_id  = null;
        if ( ! empty($inbox_wa) ) {
            $conv_id = lion_find_or_create_conversation( $contact_id, (int) $inbox_wa, $utm_data );
            if ( $conv_id && ! empty($utm_data) ) {
                lion_log( "UTMs salvos na conversa WA #$conv_id: " . implode(', ', array_keys($utm_data)), 'INFO' );
            }
        }

        // 5. Execute action
        $action_type = $matched['action_type'] ?? 'canned';

        if ( $action_type === 'template' ) {
            $inbox_data = lion_api( 'GET', '/inboxes' );
            $outbox_channel = '';
            if ( ! is_wp_error($inbox_data) ) {
                foreach ( ($inbox_data['payload'] ?? []) as $ib ) {
                    if ( (int)($ib['id'] ?? 0) === (int)$inbox_wa ) {
                        $outbox_channel = $ib['channel_type'] ?? '';
                        break;
                    }
                }
            }
            if ( $outbox_channel !== 'Channel::Whatsapp' ) {
                $action_type = 'canned';
                lion_log( "Ação 'template' forçada para 'canned' — inbox {$inbox_wa} não é Cloud API.", 'AVISO' );
            }
        }

        if ( $action_type === 'canned' && $conv_id ) {
            $canned_id = $matched['canned_id'] ?? '';
            if ( ! empty($canned_id) ) {
                $cr = lion_api( 'GET', "/canned_responses" );
                $sent = false;
                if ( ! is_wp_error($cr) ) {
                    foreach ( $cr as $c ) {
                        if ( (int)$c['id'] === (int)$canned_id && ! empty($c['content']) ) {
                            $msg = $c['content'];
                            $var_replacements = [
                                [ '{{nome}}', '{nome}', '{{name}}', '{name}' ],
                                [ '{{email}}', '{email}', '{{e-mail}}', '{e-mail}' ],
                                [ '{{telefone}}', '{telefone}', '{{phone}}', '{phone}', '{{whatsapp}}', '{whatsapp}' ],
                                [ '{{formulario}}', '{formulario}', '{{form}}', '{form}' ],
                            ];
                            $var_values = [ $name, $email, $phone_final, $form_name ];
                            foreach ( $var_replacements as $i => $patterns ) {
                                $msg = str_replace( $patterns, $var_values[$i], $msg );
                            }
                            $send = lion_api( 'POST', "/conversations/$conv_id/messages", [ 'content' => $msg, 'message_type' => 'outgoing' ] );
                            $sent = ! is_wp_error($send);
                            break;
                        }
                    }
                }
                if ( $sent ) {
                    lion_log( "Resposta pronta (ID: $canned_id) enviada para $name (Conversa #$conv_id)", 'SUCESSO' );
                } else {
                    lion_log( "Erro ao enviar resposta pronta (ID: $canned_id) para $name", 'ERRO' );
                }
            }
        } elseif ( $action_type === 'template' && $conv_id ) {
            $tpl_name = $matched['template_name'] ?? '';
            if ( ! empty($tpl_name) ) {
                $var_map = [ 'nome' => $name, 'email' => $email, 'telefone' => $phone_final, 'formulario' => $form_name ];
                $body_params = [];
                $header_params = [];
                for ( $i = 1; $i <= 10; $i++ ) {
                    $body_key = "tpl_var_body_{$i}";
                    if ( isset( $matched[$body_key] ) ) {
                        $val = $matched[$body_key];
                        if ( $val === 'custom' ) {
                            $body_params[] = $matched["{$body_key}_custom"] ?? '';
                        } elseif ( isset( $var_map[$val] ) ) {
                            $body_params[] = $var_map[$val];
                        } else {
                            $body_params[] = '';
                        }
                    }
                    $header_key = "tpl_var_header_{$i}";
                    if ( isset( $matched[$header_key] ) ) {
                        $val = $matched[$header_key];
                        if ( $val === 'custom' ) {
                            $header_params[] = $matched["{$header_key}_custom"] ?? '';
                        } elseif ( isset( $var_map[$val] ) ) {
                            $header_params[] = $var_map[$val];
                        } else {
                            $header_params[] = '';
                        }
                    }
                }

                $processed_params = [];
                if ( ! empty($body_params) ) $processed_params['body'] = $body_params;
                if ( ! empty($header_params) ) $processed_params['header'] = $header_params;

                $tpl_payload = [
                    'content'         => '',
                    'message_type'    => 'outgoing',
                    'template_params' => [
                        'name'             => $tpl_name,
                        'language'         => $matched['template_language'] ?? 'pt_BR',
                        'processed_params' => $processed_params,
                    ],
                ];

                $send = lion_api( 'POST', "/conversations/$conv_id/messages", $tpl_payload );
                if ( is_wp_error($send) ) {
                    lion_log( "Erro ao enviar template '$tpl_name': " . $send->get_error_message(), 'ERRO' );
                } else {
                    lion_log( "Template '$tpl_name' enviado para $name (Conversa #$conv_id)", 'SUCESSO' );
                }
            }
        } elseif ( $action_type === 'automation' && $conv_id ) {
            $auto_id = $matched['automation_id'] ?? '';
            if ( ! empty($auto_id) ) {
                lion_log( "Conversa #$conv_id criada — automação webhook (ID: $auto_id) será disparada automaticamente.", 'SUCESSO' );
            }
        }

        // 5. Apply tags
        $tag_raw = trim( $matched['tag'] ?? '' );
        if ( ! empty($tag_raw) ) {
            $tags = array_filter( array_map( 'trim', explode( ',', $tag_raw ) ) );
            if ( ! empty($tags) ) {
                $existing = [];
                $lr = lion_api( 'GET', "/contacts/$contact_id/labels" );
                if ( ! is_wp_error($lr) ) {
                    foreach ( ($lr['payload'] ?? []) as $l ) $existing[] = $l['title'] ?? $l;
                }
                foreach ( $tags as $tag ) {
                    if ( ! in_array($tag, $existing) ) $existing[] = $tag;
                }
                lion_api( 'POST', "/contacts/$contact_id/labels", [ 'labels' => $existing ] );

                if ( $conv_id ) {
                    $conv_labels = [];
                    $clr = lion_api( 'GET', "/conversations/$conv_id/labels" );
                    if ( ! is_wp_error($clr) ) {
                        foreach ( ($clr['payload'] ?? []) as $l ) $conv_labels[] = is_string($l) ? $l : ($l['title'] ?? '');
                    }
                    foreach ( $tags as $tag ) {
                        if ( ! in_array($tag, $conv_labels) ) $conv_labels[] = $tag;
                    }
                    lion_api( 'POST', "/conversations/$conv_id/labels", [ 'labels' => $conv_labels ] );
                }
                lion_log( "Tag(s) '" . implode(', ', $tags) . "' aplicada(s) no contato e conversa.", 'SUCESSO' );
            }
        }

    } catch ( \Throwable $e ) {
        lion_log( "Erro fatal: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine(), 'CRITICO' );
    }
}

// ============================================================
// HOOK: Elementor Pro
// ============================================================
add_action( 'elementor_pro/forms/new_record', function( $record, $handler ) {
    $form_name = $record->get('form_settings')['form_name'] ?? '';
    $raw = $record->get_formatted_data();
    lion_process_lead( $form_name, $raw );
}, 10, 2 );

// ============================================================
// HOOK: Contact Form 7
// ============================================================
add_action( 'wpcf7_mail_sent', function( $contact_form ) {
    $form_name = 'CF7: ' . $contact_form->title();
    $submission = WPCF7_Submission::get_instance();
    if ( ! $submission ) return;
    $posted = $submission->get_posted_data();
    // CF7 usa campos como your-name, your-email — mapear para labels legíveis
    $raw = [];
    $label_map = [
        'your-name' => 'Nome', 'your-email' => 'Email', 'your-phone' => 'Telefone',
        'your-tel' => 'Telefone', 'your-message' => 'Mensagem', 'your-subject' => 'Assunto',
        'tel' => 'Telefone', 'phone' => 'Telefone', 'name' => 'Nome', 'email' => 'Email',
        'nome' => 'Nome', 'telefone' => 'Telefone', 'whatsapp' => 'Telefone', 'celular' => 'Telefone',
    ];
    foreach ( $posted as $key => $value ) {
        if ( strpos( $key, '_wpcf7' ) === 0 ) continue; // campos internos do CF7
        $label = $label_map[ $key ] ?? ucfirst( str_replace( [ '-', '_' ], ' ', $key ) );
        $raw[ $label ] = is_array($value) ? implode(', ', $value) : $value;
    }
    lion_process_lead( $form_name, $raw );
});

// ============================================================
// HOOK: WPForms
// ============================================================
add_action( 'wpforms_process_complete', function( $fields, $entry, $form_data, $entry_id ) {
    $form_name = 'WPForms: ' . ( $form_data['settings']['form_title'] ?? 'Sem nome' );
    $raw = [];
    foreach ( $fields as $field ) {
        $label = $field['name'] ?? $field['type'] ?? 'Campo';
        $value = $field['value'] ?? '';
        if ( ! empty($value) ) {
            $raw[ $label ] = $value;
        }
    }
    lion_process_lead( $form_name, $raw );
}, 10, 4 );
