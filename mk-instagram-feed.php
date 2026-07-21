<?php
/**
 * Plugin Name: MK Instagram Feed
 * Plugin URI: https://github.com/logingrupa/mk-instagram-feed
 * Description: Server-side cached Instagram feed (Graph API, Instagram Login). Shortcode [mk_instagram]. Zero front-end JS, no third-party scripts.
 * Version: 1.1.0
 * Author: Logingrupa
 * License: MIT
 */

if (!defined('ABSPATH')) {
    exit;
}

const MK_IG_TOKEN_OPTION    = 'mk_ig_token';
const MK_IG_LASTGOOD_OPTION = 'mk_ig_feed_lastgood';
const MK_IG_TRANSIENT       = 'mk_ig_feed_v3';
const MK_IG_PROFILE_TRANSIENT = 'mk_ig_profile_v1';
const MK_IG_CACHE_TTL       = 2 * HOUR_IN_SECONDS;
const MK_IG_GRAPH           = 'https://graph.instagram.com';
const MK_IG_ERROR_TRANSIENT = 'mk_ig_error_backoff';
const MK_IG_ERROR_TTL       = 5 * MINUTE_IN_SECONDS;
const MK_IG_MAX_COUNT       = 50;
/* Insights are fetched one request per video inside the page render, so this
   timeout is the per-video worst case a visitor can be made to wait. */
const MK_IG_INSIGHTS_TIMEOUT = 2;

function mk_ig_get_token()
{
    $token = trim((string) get_option(MK_IG_TOKEN_OPTION, ''));
    if ($token !== '') {
        return $token;
    }
    $env = $_ENV['FB_META_IG_KEY'] ?? ($_SERVER['FB_META_IG_KEY'] ?? getenv('FB_META_IG_KEY'));
    return is_string($env) ? trim($env) : '';
}

function mk_ig_fetch_remote($count)
{
    $token = mk_ig_get_token();
    if ($token === '') {
        return new WP_Error('mk_ig_no_token', 'Instagram access token is not set.');
    }

    $url = add_query_arg(
        [
            'fields'       => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,username,like_count,comments_count',
            'limit'        => mk_ig_clamp_count($count),
            'access_token' => $token,
        ],
        MK_IG_GRAPH . '/me/media'
    );

    $response = wp_remote_get($url, ['timeout' => 8]);
    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code !== 200 || !isset($body['data'])) {
        $message = $body['error']['message'] ?? 'Unexpected Instagram API response.';
        return new WP_Error('mk_ig_api_error', $message, ['code' => $code]);
    }

    return $body['data'];
}

function mk_ig_clamp_count($count)
{
    return max(1, min(MK_IG_MAX_COUNT, (int) $count));
}

/**
 * Cache key is per requested count: a [mk_instagram count="30"] block must not
 * be served a nine-item payload cached earlier by a count="9" block.
 */
function mk_ig_transient_key($count)
{
    return MK_IG_TRANSIENT . '_' . mk_ig_clamp_count($count);
}

function mk_ig_get_media($count)
{
    $count = mk_ig_clamp_count($count);
    $key   = mk_ig_transient_key($count);

    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }

    // While the API is failing, serve the last good copy without retrying on
    // every single page view.
    if (get_transient(MK_IG_ERROR_TRANSIENT)) {
        return get_option(MK_IG_LASTGOOD_OPTION, []);
    }

    $fresh = mk_ig_fetch_remote($count);
    if (is_wp_error($fresh)) {
        set_transient(MK_IG_ERROR_TRANSIENT, 1, MK_IG_ERROR_TTL);
        return get_option(MK_IG_LASTGOOD_OPTION, []);
    }

    $fresh = mk_ig_attach_views($fresh, mk_ig_get_token());
    set_transient($key, $fresh, MK_IG_CACHE_TTL);
    update_option(MK_IG_LASTGOOD_OPTION, $fresh, false);
    return $fresh;
}

/**
 * Drop every cached payload. Handy after changing the token:
 *   wp eval 'mk_ig_flush_cache();'
 */
function mk_ig_flush_cache()
{
    for ($i = 1; $i <= MK_IG_MAX_COUNT; $i++) {
        delete_transient(mk_ig_transient_key($i));
    }
    delete_transient(MK_IG_PROFILE_TRANSIENT);
    delete_transient(MK_IG_ERROR_TRANSIENT);
}

function mk_ig_attach_views($items, $token)
{
    foreach ($items as &$item) {
        if (($item['media_type'] ?? '') !== 'VIDEO' || empty($item['id'])) {
            continue;
        }
        $url = add_query_arg(
            ['metric' => 'views', 'access_token' => $token],
            MK_IG_GRAPH . '/' . rawurlencode($item['id']) . '/insights'
        );
        $response = wp_remote_get($url, ['timeout' => MK_IG_INSIGHTS_TIMEOUT]);
        if (is_wp_error($response)) {
            continue;
        }
        $body  = json_decode(wp_remote_retrieve_body($response), true);
        $value = $body['data'][0]['values'][0]['value'] ?? ($body['data'][0]['total_value']['value'] ?? null);
        if ($value !== null) {
            $item['views'] = (int) $value;
        }
    }
    unset($item);
    return $items;
}

function mk_ig_image_src($item)
{
    $type = $item['media_type'] ?? 'IMAGE';
    if ($type === 'VIDEO' && !empty($item['thumbnail_url'])) {
        return $item['thumbnail_url'];
    }
    return $item['media_url'] ?? '';
}

function mk_ig_get_profile()
{
    $cached = get_transient(MK_IG_PROFILE_TRANSIENT);
    if (is_array($cached)) {
        return $cached;
    }

    $token = mk_ig_get_token();
    $fallback = ['username' => '', 'avatar' => ''];
    if ($token === '') {
        return $fallback;
    }

    $url = add_query_arg(
        ['fields' => 'username,profile_picture_url', 'access_token' => $token],
        MK_IG_GRAPH . '/me'
    );
    $response = wp_remote_get($url, ['timeout' => 8]);
    if (is_wp_error($response)) {
        return $fallback;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['username'])) {
        return $fallback;
    }

    $profile = [
        'username' => $body['username'],
        'avatar'   => $body['profile_picture_url'] ?? '',
    ];
    set_transient(MK_IG_PROFILE_TRANSIENT, $profile, DAY_IN_SECONDS);
    return $profile;
}

function mk_ig_render($atts)
{
    $atts  = shortcode_atts(['count' => 9, 'columns' => 3], $atts, 'mk_instagram');
    $count = mk_ig_clamp_count($atts['count']);
    $cols  = max(1, min(6, (int) $atts['columns']));
    $items = array_slice((array) mk_ig_get_media($count), 0, $count);

    if (empty($items)) {
        if (current_user_can('manage_options')) {
            return '<p class="mk-ig-empty">Instagram feed is not connected yet. Set the access token to populate this grid.</p>';
        }
        return '';
    }

    $profile = mk_ig_get_profile();

    $cards = '';
    foreach ($items as $item) {
        $src = mk_ig_image_src($item);
        if ($src === '') {
            continue;
        }
        $type    = $item['media_type'] ?? 'IMAGE';
        $video   = ($type === 'VIDEO') ? ($item['media_url'] ?? '') : '';
        $caption = isset($item['caption']) ? wp_strip_all_tags($item['caption']) : '';
        $cards  .= sprintf(
            '<a class="mk-ig-tile" href="%s" target="_blank" rel="noopener nofollow"'
            . ' data-video="%s" data-caption="%s" data-likes="%s" data-comments="%s" data-views="%s">'
            . '<img src="%s" alt="%s" loading="lazy" decoding="async" width="320" height="320">%s</a>',
            esc_url($item['permalink'] ?? '#'),
            esc_url($video),
            esc_attr($caption),
            isset($item['like_count']) ? (int) $item['like_count'] : -1,
            isset($item['comments_count']) ? (int) $item['comments_count'] : -1,
            isset($item['views']) ? (int) $item['views'] : -1,
            esc_url($src),
            esc_attr(wp_html_excerpt($caption !== '' ? $caption : 'Instagram', 80, '…')),
            mk_ig_badge($type)
        );
    }

    $grid = '<div class="mk-ig-grid" style="--mk-ig-cols:' . $cols . '">' . $cards . '</div>';

    static $assets_done = false;
    if ($assets_done) {
        return $grid;
    }
    $assets_done = true;

    return mk_ig_assets($profile) . $grid;
}
add_shortcode('mk_instagram', 'mk_ig_render');

function mk_ig_register_block()
{
    if (!function_exists('register_block_type')) {
        return;
    }

    wp_register_script(
        'mk-ig-block',
        plugins_url('mk-instagram-feed-block.js', __FILE__),
        ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n'],
        '1.0.0',
        true
    );

    register_block_type('mk/instagram', [
        'api_version'     => 3,
        'editor_script'   => 'mk-ig-block',
        'render_callback' => 'mk_ig_render',
        'attributes'      => [
            'count'   => ['type' => 'number', 'default' => 9],
            'columns' => ['type' => 'number', 'default' => 3],
        ],
    ]);
}
add_action('init', 'mk_ig_register_block');

function mk_ig_badge($type)
{
    $icons = [
        'VIDEO'          => '<path d="M8 5v14l11-7z"/>',
        'CAROUSEL_ALBUM' => '<path d="M7 4h11a2 2 0 0 1 2 2v11h-2V6H7V4zm-3 4h11a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2z"/>',
    ];
    if (!isset($icons[$type])) {
        return '';
    }
    return '<span class="mk-ig-badge"><svg viewBox="0 0 24 24" aria-hidden="true">' . $icons[$type] . '</svg></span>';
}

function mk_ig_assets($profile)
{
    $user   = $profile['username'] ?? '';
    $avatar = $profile['avatar'] ?? '';

    $css = '.mk-ig-grid{display:grid;grid-template-columns:repeat(var(--mk-ig-cols,3),1fr);gap:8px}'
        . '.mk-ig-tile{position:relative;display:block;aspect-ratio:9/16;overflow:hidden;border-radius:8px}'
        . '.mk-ig-tile img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .3s}'
        . '.mk-ig-tile:hover img{transform:scale(1.05)}'
        . '.mk-ig-badge{position:absolute;top:8px;right:8px;width:24px;height:24px;pointer-events:none;filter:drop-shadow(0 1px 2px rgba(0,0,0,.55))}'
        . '.mk-ig-badge svg{width:100%;height:100%;fill:#fff}'
        . '.mk-ig-modal[hidden]{display:none}'
        . '.mk-ig-modal{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center}'
        . '.mk-ig-bg{position:absolute;inset:0;background:rgba(0,0,0,.85)}'
        . '.mk-ig-card{position:relative;z-index:2;width:min(440px,94vw);max-height:92vh;background:#fff;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;font-family:inherit}'
        . '.mk-ig-head{display:flex;align-items:center;gap:10px;padding:11px 14px;text-decoration:none;flex:0 0 auto}'
        . '.mk-ig-ava{width:34px;height:34px;border-radius:50%;object-fit:cover;background:linear-gradient(45deg,#f09433,#dc2743,#bc1888);flex:0 0 auto}'
        . '.mk-ig-user{font-weight:600;font-size:14px;color:#262626}'
        . '.mk-ig-head:hover .mk-ig-user{text-decoration:underline}'
        . '.mk-ig-media{background:#000;display:flex;align-items:center;justify-content:center;flex:0 0 auto}'
        . '.mk-ig-media img,.mk-ig-media video{width:100%;max-height:58vh;object-fit:contain;display:block}'
        . '.mk-ig-body{flex:1 1 auto;min-height:0;padding:10px 14px;overflow-y:auto}'
        . '.mk-ig-stats{display:flex;gap:16px;align-items:center;margin-bottom:8px}'
        . '.mk-ig-stats span{display:inline-flex;align-items:center;gap:6px;font-size:14px;font-weight:600;color:#262626}'
        . '.mk-ig-stats svg{width:22px;height:22px}'
        . '.mk-ig-cap{font-size:14px;line-height:1.5;color:#262626;margin:0;white-space:pre-wrap;word-break:break-word}'
        . '.mk-ig-cap b{font-weight:600;margin-right:6px}'
        . '.mk-ig-foot{flex:0 0 auto;padding:12px 14px;border-top:1px solid #efefef}'
        . '.mk-ig-link{display:block;text-align:center;background:#0095f6;color:#fff;font-weight:600;font-size:14px;text-decoration:none;padding:10px 18px;border-radius:8px}'
        . '.mk-ig-link:hover{background:#1877f2}'
        . '.mk-ig-close,.mk-ig-prev,.mk-ig-next{position:absolute;z-index:3;background:rgba(0,0,0,.45);color:#fff;border:0;cursor:pointer;border-radius:999px;width:44px;height:44px;font-size:26px;line-height:1;display:flex;align-items:center;justify-content:center}'
        . '.mk-ig-close{top:14px;right:14px}.mk-ig-prev{left:14px;top:50%;transform:translateY(-50%)}.mk-ig-next{right:14px;top:50%;transform:translateY(-50%)}'
        . '@media(max-width:600px){.mk-ig-tile{aspect-ratio:10/16}}'
        . '@media(max-width:520px){.mk-ig-prev{left:4px}.mk-ig-next{right:4px}}';

    $avaHtml = $avatar !== ''
        ? '<img class="mk-ig-ava" src="' . esc_url($avatar) . '" alt="" referrerpolicy="no-referrer">'
        : '<span class="mk-ig-ava"></span>';
    $profileUrl = $user !== '' ? 'https://www.instagram.com/' . rawurlencode($user) . '/' : '#';

    $heart   = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#ed4956" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>';
    $bubble  = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#262626" d="M12 2C6.48 2 2 5.92 2 10.5c0 2.3 1.13 4.38 2.96 5.9-.13 1.26-.6 2.9-1.3 4.1 1.7-.36 3.5-.96 4.6-1.66 1.15.36 2.4.56 3.74.56 5.52 0 10-3.92 10-8.5S17.52 2 12 2z"/></svg>';
    $playic  = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#262626" d="M8 5v14l11-7z"/></svg>';

    $modal = '<div class="mk-ig-modal" id="mk-ig-modal" hidden>'
        . '<div class="mk-ig-bg" data-close></div>'
        . '<button class="mk-ig-close" data-close aria-label="Aizvērt">&times;</button>'
        . '<button class="mk-ig-prev" aria-label="Iepriekšējais">&lsaquo;</button>'
        . '<button class="mk-ig-next" aria-label="Nākamais">&rsaquo;</button>'
        . '<div class="mk-ig-card">'
        . '<a class="mk-ig-head" href="' . esc_url($profileUrl) . '" target="_blank" rel="noopener nofollow">'
        . $avaHtml . '<span class="mk-ig-user">' . esc_html($user) . '</span></a>'
        . '<div class="mk-ig-media"></div>'
        . '<div class="mk-ig-body">'
        . '<div class="mk-ig-stats"><span class="mk-ig-likes">' . $heart . '<b></b></span>'
        . '<span class="mk-ig-comments">' . $bubble . '<b></b></span>'
        . '<span class="mk-ig-views">' . $playic . '<b></b></span></div>'
        . '<p class="mk-ig-cap"></p>'
        . '</div>'
        . '<div class="mk-ig-foot"><a class="mk-ig-link" target="_blank" rel="noopener nofollow">Atvērt Instagram</a></div>'
        . '</div></div>';

    $js = '(function(){var m=document.getElementById("mk-ig-modal");if(!m)return;'
        . 'var user=' . wp_json_encode($user) . ';'
        . 'var media=m.querySelector(".mk-ig-media"),cap=m.querySelector(".mk-ig-cap"),'
        . 'link=m.querySelector(".mk-ig-link"),'
        . 'likesEl=m.querySelector(".mk-ig-likes"),commEl=m.querySelector(".mk-ig-comments"),'
        . 'viewsEl=m.querySelector(".mk-ig-views"),'
        . 'tiles=[].slice.call(document.querySelectorAll(".mk-ig-grid .mk-ig-tile")),i=0;'
        . 'tiles.forEach(function(t){var u=t.querySelector("img").src;if(u){var p=new Image();p.src=u;}});'
        . 'function fmt(n){return n>=1000?(n/1000).toFixed(1).replace(/\\.0$/,"")+"k":""+n;}'
        . 'function show(n){i=(n+tiles.length)%tiles.length;var t=tiles[i];'
        . 'var poster=t.querySelector("img").src,video=t.getAttribute("data-video")||"";'
        . 'var c=t.getAttribute("data-caption")||"",likes=parseInt(t.getAttribute("data-likes"),10),'
        . 'comments=parseInt(t.getAttribute("data-comments"),10),views=parseInt(t.getAttribute("data-views"),10);'
        . 'media.innerHTML="";'
        . 'if(video){var v=document.createElement("video");v.src=video;v.poster=poster;v.controls=true;'
        . 'v.autoplay=true;v.loop=true;v.muted=false;v.setAttribute("playsinline","");media.appendChild(v);'
        . 'var pp=v.play();if(pp&&pp.catch)pp.catch(function(){v.muted=true;v.play().catch(function(){});});}'
        . 'else{var im=document.createElement("img");im.src=poster;im.alt="";media.appendChild(im);}'
        . 'if(likes>=0){likesEl.style.display="";likesEl.querySelector("b").textContent=fmt(likes);}else{likesEl.style.display="none";}'
        . 'if(comments>=0){commEl.style.display="";commEl.querySelector("b").textContent=fmt(comments);}else{commEl.style.display="none";}'
        . 'if(views>=0){viewsEl.style.display="";viewsEl.querySelector("b").textContent=fmt(views);}else{viewsEl.style.display="none";}'
        . 'cap.innerHTML="";if(user){var b=document.createElement("b");b.textContent=user;cap.appendChild(b);}'
        . 'cap.appendChild(document.createTextNode(c));cap.style.display=(c||user)?"":"none";'
        . 'link.href=t.getAttribute("href");}'
        . 'function open(n){show(n);m.hidden=false;document.body.style.overflow="hidden";}'
        . 'function close(){media.innerHTML="";m.hidden=true;document.body.style.overflow="";}'
        . 'tiles.forEach(function(t,n){t.addEventListener("click",function(e){e.preventDefault();open(n);});});'
        . 'm.querySelector(".mk-ig-next").addEventListener("click",function(){show(i+1);});'
        . 'm.querySelector(".mk-ig-prev").addEventListener("click",function(){show(i-1);});'
        . '[].forEach.call(m.querySelectorAll("[data-close]"),function(el){el.addEventListener("click",close);});'
        . 'var sx=0;m.addEventListener("touchstart",function(e){sx=e.changedTouches[0].clientX;},{passive:true});'
        . 'm.addEventListener("touchend",function(e){var dx=e.changedTouches[0].clientX-sx;'
        . 'if(Math.abs(dx)>40)show(dx<0?i+1:i-1);},{passive:true});'
        . 'document.addEventListener("keydown",function(e){if(m.hidden)return;'
        . 'if(e.key==="Escape")close();else if(e.key==="ArrowRight")show(i+1);else if(e.key==="ArrowLeft")show(i-1);});})();';

    return '<style>' . $css . '</style>' . $modal . '<script>' . $js . '</script>';
}

function mk_ig_refresh_token()
{
    $token = mk_ig_get_token();
    if ($token === '') {
        return;
    }

    $url = add_query_arg(
        ['grant_type' => 'ig_refresh_token', 'access_token' => $token],
        MK_IG_GRAPH . '/refresh_access_token'
    );

    $response = wp_remote_get($url, ['timeout' => 8]);
    if (is_wp_error($response)) {
        return;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['access_token'])) {
        return;
    }

    update_option(MK_IG_TOKEN_OPTION, $body['access_token'], false);
    mk_ig_flush_cache();
}
add_action('mk_ig_refresh_token_event', 'mk_ig_refresh_token');

function mk_ig_schedule()
{
    if (!wp_next_scheduled('mk_ig_refresh_token_event')) {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'mk_ig_refresh_token_event');
    }
}
add_action('init', 'mk_ig_schedule');
