<?php

declare(strict_types=1);

/*
 * Defaults for the screenshot catalogue. Hosts override by publishing
 * (`vendor:publish --tag=filament-screenshot-catalogue-config`)
 * and editing the generated copy. Panel descriptors are registered
 * separately via the `PanelRegistry::register(...)` static call — they
 * carry closures and credentials that don't fit a pure-PHP config file.
 */

return [
    /*
     * S3 disk to upload screenshots + index.html to. The default value
     * matches the conventional `s3_public` disk; change here if your app
     * uses a different disk name.
     */
    'disk' => env('PANEL_SCREENSHOT_CATALOGUE_DISK', 's3_public'),

    /*
     * Top-level S3 path prefix. Layout below this is:
     *   {prefix}/{env}/{panel}/{version}/{slug}/{viewport}-{mode}.png
     * with `index.html` and `favicon.svg` next to each version's tree.
     */
    'path_prefix' => env('PANEL_SCREENSHOT_CATALOGUE_PATH_PREFIX', 'screenshots'),

    /*
     * Capture viewports — name → { name, width, height }. Defaults match
     * common device sizes (laptop/tablet/phone). Add or override here to
     * standardise on your own breakpoints.
     */
    'viewports' => [
        'desktop' => ['name' => 'desktop', 'width' => 1280, 'height' => 800],
        'tablet' => ['name' => 'tablet', 'width' => 768, 'height' => 1024],
        'mobile' => ['name' => 'mobile', 'width' => 375, 'height' => 812],
    ],

    /*
     * Sitemap slugs captured with the panel's main sidebar collapsed to
     * its icon rail, so wide pages (a mail client, a kanban board) get the
     * room. Wildcards follow Str::is(), e.g. 'mail*'. Every other page is
     * captured expanded. `screenshot:capture --sidebar=collapsed|expanded`
     * overrides this for one run. Only applies to panels with
     * sidebarCollapsibleOnDesktop(), and only at desktop widths.
     */
    'collapsed_sidebar_pages' => [],

    /*
     * Optional CSS injected into every captured page just before the
     * screenshot is taken. Use this to lock host-specific scroll-driven
     * animations (shrinking topbars, hover-only states, etc.) into a
     * stable state for the catalogue.
     *
     * Example for a Filament theme that animates the topbar on scroll:
     *
     * 'capture_time_css' => '
     *     .fi-topbar { padding-block: 1rem !important; }
     *     .fi-main { padding-top: 7rem !important; }
     * ',
     */
    'capture_time_css' => env('PANEL_SCREENSHOT_CATALOGUE_CAPTURE_CSS', ''),

    /*
     * Path to the Playwright runner script. Defaults to the version
     * shipped with the package. Override if you've published the script
     * (via `vendor:publish --tag=filament-screenshot-catalogue-js`)
     * and customised it.
     */
    'capture_script' => env(
        'PANEL_SCREENSHOT_CATALOGUE_CAPTURE_SCRIPT',
        null, // null = use the package's bundled script
    ),

    /*
     * Hosts `screenshot:url` may open, for both --url and --login-url.
     * The host of `app.url`, its subdomains and every registered panel
     * domain are always allowed; list any other host here. Wildcards
     * follow Str::is(), e.g. '*.example.com'. Only http and https URLs
     * are ever opened, so file:// and similar schemes are refused.
     */
    'url_allowed_hosts' => array_filter(explode(',', (string) env('PANEL_SCREENSHOT_CATALOGUE_URL_ALLOWED_HOSTS', ''))),

    /*
     * Sitemap entries to omit from the catalogue. Match against the
     * `slug` field emitted by `php artisan panel:sitemap`, which uses
     * Filament's `{resource}.{page}` form for resource pages
     * (e.g. `coaching-sessions.index`, `my-consent.edit`) and the page
     * class's slug for custom pages (e.g. `dashboard`,
     * `end-user-change-password`). They are NOT URL slugs.
     *
     * Use this to hide pages that are intentionally empty without
     * specific fixtures, deprecated UI on the way out, or anything else
     * you don't want in the agent / QA walk.
     */
    'excluded_slugs' => [
        // 'media-resources.index',
        // 'documentation.index',
    ],

    /*
     * Brand assets uploaded alongside `index.html` so the rendered
     * catalogue picks them up by relative URL. Both are optional — leave
     * null to skip and the index falls back to the catalogue's default
     * appearance.
     *
     *   name    Heading text rendered next to the logo. Defaults to
     *           'Panel Screenshots' if blank.
     *   logo    Absolute path on disk to the wordmark/logo SVG/PNG. The
     *           catalogue's index renders on a dark surface, so a
     *           dark-mode-friendly variant reads best.
     *   favicon Absolute path on disk to the favicon SVG/PNG. Avoids the
     *           404/403 you'd otherwise get on /favicon.ico.
     */
    'brand' => [
        'name' => env('PANEL_SCREENSHOT_CATALOGUE_BRAND_NAME', 'Panel Screenshots'),
        'logo' => env('PANEL_SCREENSHOT_CATALOGUE_BRAND_LOGO'),
        'favicon' => env('PANEL_SCREENSHOT_CATALOGUE_BRAND_FAVICON'),
    ],

    /*
     * Queue name for the capture + index-rebuild + auto-sync jobs.
     *
     * Captures take 30 seconds to several minutes per page and would
     * starve a generic `default` queue worker that's also handling fast
     * app jobs (or get killed by Horizon's typical 60s default-queue
     * timeout). Use a dedicated queue that hosts can wire to a
     * long-timeout supervisor. For Horizon, add a supervisor with
     * `queue: ['screenshots']`, `timeout: 600`, `maxProcesses: 1`. For a
     * standalone worker:
     *
     *   php artisan queue:work --queue=screenshots --timeout=600 --tries=2
     *
     * Set to `'default'` to share the app's default queue (legacy
     * behaviour pre-5.3.0).
     */
    'queue' => env('PANEL_SCREENSHOT_CATALOGUE_QUEUE', 'screenshots'),
];
