<?php
/**
 * Plugin Name: Saberin PWA
 * Description: Progressive Web App support for Saberin Khonj website.
 * Version: 1.4.0
 * Author: Saberin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Home path only, e.g. "/" or "/blog/".
 */
function saberin_pwa_home_path() {
    $path = wp_parse_url(home_url('/'), PHP_URL_PATH);
    if (!$path) {
        return '/';
    }
    return trailingslashit($path);
}

/**
 * Detect if current request is for our SW or manifest.
 * Supports both clean URLs and query-string fallbacks (more reliable on some hosts).
 */
function saberin_pwa_request_type() {
    // Query string fallback – always hits WordPress
    if (isset($_GET['saberin_pwa'])) {
        $v = sanitize_key(wp_unslash($_GET['saberin_pwa']));
        if ($v === 'sw' || $v === 'service-worker') {
            return 'sw';
        }
        if ($v === 'manifest') {
            return 'manifest';
        }
    }

    if (empty($_SERVER['REQUEST_URI'])) {
        return '';
    }

    $request_path = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
    if (!$request_path) {
        return '';
    }

    // Normalize
    $request_path = rawurldecode($request_path);
    $home_path    = untrailingslashit(saberin_pwa_home_path());
    if ($home_path && $home_path !== '/' && strpos($request_path, $home_path) === 0) {
        $request_path = substr($request_path, strlen($home_path));
        if ($request_path === '') {
            $request_path = '/';
        }
    }

    $request_path = '/' . ltrim($request_path, '/');

    if ($request_path === '/service-worker.js' || $request_path === '/sw.js') {
        return 'sw';
    }
    if ($request_path === '/manifest.webmanifest' || $request_path === '/manifest.json') {
        return 'manifest';
    }

    return '';
}

function saberin_pwa_endpoints() {
    $type = saberin_pwa_request_type();
    if ($type === '') {
        return;
    }

    if ($type === 'sw') {
        nocache_headers();
        header('Content-Type: application/javascript; charset=UTF-8');
        header('Service-Worker-Allowed: ' . saberin_pwa_home_path());
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Robots-Tag: noindex');

        $icon_url = plugins_url('icons/icon-192.png', __FILE__);
        $home_url = home_url('/');
        $icon_js  = wp_json_encode($icon_url);
        $home_js  = wp_json_encode($home_url);

        echo <<<JS
/* Saberin PWA Service Worker v1.4.0 */
const CACHE_NAME = 'saberin-pwa-v3';
const DEFAULT_ICON = {$icon_js};
const DEFAULT_URL = {$home_js};

self.addEventListener('install', event => {
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', event => {
    // Network-first; keep SW active for installability + push.
});

self.addEventListener('push', event => {
    let data = {
        title: 'صابرین',
        body: 'اعلان جدید',
        icon: DEFAULT_ICON,
        badge: DEFAULT_ICON,
        url: DEFAULT_URL,
        tag: 'saberin-push'
    };

    try {
        if (event.data) {
            const payload = event.data.json();
            data = Object.assign(data, payload);
        }
    } catch (e) {
        try {
            if (event.data) {
                data.body = event.data.text() || data.body;
            }
        } catch (e2) {}
    }

    const options = {
        body: data.body || '',
        icon: data.icon || DEFAULT_ICON,
        badge: data.badge || DEFAULT_ICON,
        image: data.image || undefined,
        tag: data.tag || 'saberin-push',
        renotify: true,
        requireInteraction: !!data.requireInteraction,
        data: { url: data.url || DEFAULT_URL }
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'صابرین', options)
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const targetUrl = (event.notification.data && event.notification.data.url)
        ? event.notification.data.url
        : DEFAULT_URL;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
            for (const client of clientList) {
                if (client.url && 'focus' in client) {
                    client.navigate(targetUrl);
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});
JS;
        exit;
    }

    if ($type === 'manifest') {
        nocache_headers();
        header('Content-Type: application/manifest+json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Robots-Tag: noindex');

        $icon_192 = plugins_url('icons/icon-192.png', __FILE__);
        $icon_512 = plugins_url('icons/icon-512.png', __FILE__);
        $start    = home_url('/');
        $scope    = home_url('/');

        // Prefer path-absolute start_url/scope relative to origin when possible
        $start_path = wp_parse_url($start, PHP_URL_PATH) ?: '/';
        $scope_path = wp_parse_url($scope, PHP_URL_PATH) ?: '/';

        $manifest = array(
            'id'               => $start_path,
            'name'             => 'صابرین',
            'short_name'       => 'صابرین',
            'description'      => 'اپلیکیشن وب صابرین',
            'start_url'        => $start_path,
            'scope'            => $scope_path,
            'display'          => 'standalone',
            'orientation'      => 'any',
            'background_color' => '#ffffff',
            'theme_color'      => '#174ea6',
            'lang'             => 'fa',
            'dir'              => 'rtl',
            'icons'            => array(
                // Separate purpose entries – required by Chrome installability checks
                array(
                    'src'     => $icon_192,
                    'sizes'   => '192x192',
                    'type'    => 'image/png',
                    'purpose' => 'any',
                ),
                array(
                    'src'     => $icon_512,
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'any',
                ),
                array(
                    'src'     => $icon_192,
                    'sizes'   => '192x192',
                    'type'    => 'image/png',
                    'purpose' => 'maskable',
                ),
                array(
                    'src'     => $icon_512,
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'maskable',
                ),
            ),
        );

        echo wp_json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}
add_action('init', 'saberin_pwa_endpoints', 0);

/** Prefer query URLs – they always reach PHP even if server blocks .js/.webmanifest */
function saberin_pwa_sw_url() {
    return add_query_arg('saberin_pwa', 'sw', home_url('/'));
}

function saberin_pwa_manifest_url() {
    return add_query_arg('saberin_pwa', 'manifest', home_url('/'));
}

function saberin_pwa_head() {
    if (is_admin()) {
        return;
    }

    $manifest = saberin_pwa_manifest_url();
    $icon_192 = plugins_url('icons/icon-192.png', __FILE__);
    $icon_512 = plugins_url('icons/icon-512.png', __FILE__);
    ?>
    <link rel="manifest" href="<?php echo esc_url($manifest); ?>">
    <meta name="theme-color" content="#174ea6">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="صابرین">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="صابرین">
    <link rel="apple-touch-icon" sizes="192x192" href="<?php echo esc_url($icon_192); ?>">
    <link rel="apple-touch-icon" sizes="512x512" href="<?php echo esc_url($icon_512); ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url($icon_192); ?>">
    <?php
}
add_action('wp_head', 'saberin_pwa_head', 1);

function saberin_pwa_register_worker() {
    if (is_admin()) {
        return;
    }

    $sw_url    = saberin_pwa_sw_url();
    $scope_url = home_url('/');
    $home_path = saberin_pwa_home_path();
    ?>
    <script>
    (function () {
        if (!('serviceWorker' in navigator)) {
            console.warn('Saberin PWA: Service Worker not supported');
            return;
        }

        var swUrl = <?php echo wp_json_encode($sw_url); ?>;
        var scope = <?php echo wp_json_encode($home_path); ?>;

        window.addEventListener('load', function () {
            navigator.serviceWorker.register(swUrl, {
                scope: scope,
                updateViaCache: 'none'
            }).then(function (reg) {
                console.log('Saberin SW registered, scope:', reg.scope);
                // Force update check
                if (reg.update) {
                    reg.update();
                }
            }).catch(function (err) {
                console.error('Saberin SW registration failed:', err);
            });
        });
    })();
    </script>
    <?php
}
add_action('wp_footer', 'saberin_pwa_register_worker', 100);

/**
 * Install banner (Android Chrome uses beforeinstallprompt;
 * iOS shows manual Add-to-Home-Screen instructions).
 */
function saberin_pwa_install_ui() {
    if (is_admin()) {
        return;
    }
    ?>
    <style>
        #saberin-pwa-install {
            display: none;
            position: fixed;
            bottom: 16px;
            left: 16px;
            right: 16px;
            z-index: 999998;
            max-width: 420px;
            margin: 0 auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 12px 40px rgba(0,0,0,.22);
            padding: 16px 18px;
            font-family: inherit;
            direction: rtl;
            border: 1px solid #e5e7eb;
        }
        #saberin-pwa-install h4 {
            margin: 0 0 6px;
            font-size: 16px;
        }
        #saberin-pwa-install p {
            margin: 0 0 12px;
            font-size: 13px;
            color: #555;
            line-height: 1.7;
        }
        #saberin-pwa-install .btns {
            display: flex;
            gap: 8px;
        }
        #saberin-pwa-install button {
            flex: 1;
            border: 0;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            cursor: pointer;
        }
        #saberin-pwa-install .primary {
            background: #174ea6;
            color: #fff;
        }
        #saberin-pwa-install .ghost {
            background: #f3f4f6;
            color: #333;
        }
    </style>
    <div id="saberin-pwa-install" role="dialog" aria-live="polite">
        <h4>نصب اپلیکیشن صابرین</h4>
        <p id="saberin-pwa-install-msg">برای دسترسی سریع‌تر و دریافت اعلان‌ها، اپ را روی گوشی نصب کنید.</p>
        <div class="btns">
            <button type="button" class="primary" id="saberin-pwa-install-btn">نصب</button>
            <button type="button" class="ghost" id="saberin-pwa-install-dismiss">بعداً</button>
        </div>
    </div>
    <script>
    (function () {
        var bar = document.getElementById('saberin-pwa-install');
        var btn = document.getElementById('saberin-pwa-install-btn');
        var dismiss = document.getElementById('saberin-pwa-install-dismiss');
        var msg = document.getElementById('saberin-pwa-install-msg');
        if (!bar || !btn || !dismiss) return;

        var dismissedKey = 'saberin_pwa_install_dismissed_v2';
        if (localStorage.getItem(dismissedKey) === '1') return;

        // Already running as installed PWA?
        var isStandalone = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
        if (isStandalone) return;

        var deferredPrompt = null;
        var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
        var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

        function showBar() {
            bar.style.display = 'block';
        }

        dismiss.addEventListener('click', function () {
            localStorage.setItem(dismissedKey, '1');
            bar.style.display = 'none';
        });

        // Android / desktop Chrome
        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferredPrompt = e;
            msg.textContent = 'اپ صابرین را به صفحه اصلی گوشی اضافه کنید.';
            btn.textContent = 'نصب';
            showBar();
        });

        btn.addEventListener('click', function () {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function (choice) {
                    deferredPrompt = null;
                    bar.style.display = 'none';
                    if (choice && choice.outcome === 'accepted') {
                        localStorage.setItem(dismissedKey, '1');
                    }
                });
                return;
            }
            if (isIOS) {
                msg.innerHTML = 'در سافاری روی دکمه <strong>اشتراک‌گذاری</strong> (□↑) بزنید و گزینه <strong>Add to Home Screen</strong> / <strong>افزودن به صفحه اصلی</strong> را انتخاب کنید.';
                btn.style.display = 'none';
                showBar();
            }
        });

        // iOS: show manual instructions after a short delay
        if (isIOS && (isSafari || true)) {
            setTimeout(function () {
                if (localStorage.getItem(dismissedKey) === '1') return;
                if (window.matchMedia('(display-mode: standalone)').matches) return;
                msg.innerHTML = 'برای نصب روی آیفون: دکمه <strong>اشتراک‌گذاری</strong> در سافاری را بزنید و <strong>Add to Home Screen</strong> را انتخاب کنید.';
                btn.textContent = 'راهنما';
                showBar();
            }, 2500);
        }

        window.addEventListener('appinstalled', function () {
            localStorage.setItem(dismissedKey, '1');
            bar.style.display = 'none';
        });
    })();
    </script>
    <?php
}
add_action('wp_footer', 'saberin_pwa_install_ui', 101);

function saberin_pwa_activate() {
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'saberin_pwa_activate');

function saberin_pwa_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'saberin_pwa_deactivate');
