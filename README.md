# Filament Panel Screenshot Catalogue

Capture every page of a Filament v5 panel — at desktop, tablet, and mobile breakpoints, in light and dark mode — upload to S3, and publish a browsable, link-shareable index. Designed as the data source for visual QA, design reviews, marketing assets, and AI-driven visual regression workflows.

## What you get

```bash
php artisan panel:sitemap --panel=admin                  # discover every URL
php artisan screenshot:dispatch --panel=admin --tag=latest   # queue per-page capture jobs
php artisan screenshot:capture --panel=admin --page=dashboard --tag=latest   # one-off sync run
php artisan screenshot:rebuild-index --panel=admin --tag=latest   # rebuild index.html only
```

The pipeline is:

1. **Sitemap generator** walks the panel's resources, custom pages and auth screens, picks a representative record for `view`/`edit` URLs, and saves a JSON manifest to `storage/app/sitemap-{panel}.json`.
2. **Capture jobs** drive Playwright through every entry × every viewport × every mode, save PNGs locally, and upload to your configured S3 disk.
3. **Index builder** lists the run's S3 prefix and renders `index.html` with a sticky sidebar nav, scrollspy, lightbox, and one section per page.
4. **Output** lives at `s3://{disk}/{prefix}/{env}/{panel}/{version}/` with a top-level `index.html` and `{slug}/{viewport}-{mode}.png` per shot.

## Install

This is a development tool — most consumers will want it as a `require-dev` dependency so it never ships to production:

```bash
composer require --dev visualbuilder/filament-screenshot-catalogue
```

(If you genuinely need the artisan commands available in production — e.g. you run captures from a CI job that mounts production-like infrastructure — drop the `--dev` flag.)

The package's commands auto-register. Then make sure both Composer and Node prerequisites are in place:

| Dependency | Why it's needed | How to install |
|---|---|---|
| `aws/aws-sdk-php` + an S3 disk | Uploads + serves the catalogue | Already pulled in transitively. Configure an S3 disk in `config/filesystems.php` (default expected name: `s3_public`). |
| Node 18+ | Runs the Playwright runner | System-level (`apt install nodejs` / `brew install node` / etc.). |
| Playwright | Drives the headless browser | `npm install playwright && npx playwright install chromium` in the host project root. |
| A canonical "screenshot user" per panel | The runner logs in as this user and the sitemap generator uses them to find sample records | Seeded by the host (see *Panel registration* below). |

Optionally publish the config, the Node runner, or the bundled Claude Code skill:

```bash
php artisan vendor:publish --tag=filament-screenshot-catalogue-config         # override defaults
php artisan vendor:publish --tag=filament-screenshot-catalogue-js             # custom Playwright runner
php artisan vendor:publish --tag=filament-screenshot-catalogue-claude-skills  # /screenshot-catalogue slash command
```

The Claude skill installs into the host's `.claude/commands/screenshot-catalogue.md` — gives Claude the full command surface, troubleshooting tips, and the `PanelDescriptor` registration pattern as context.

## Panel registration

Hosts tell the package which panels to capture by registering a `PanelDescriptor` per panel — typically inside a small service provider:

```php
namespace App\Providers;

use Filament\Facades\Filament;
use Illuminate\Support\ServiceProvider;
use Visualbuilder\FilamentScreenshotCatalogue\PanelDescriptor;
use Visualbuilder\FilamentScreenshotCatalogue\PanelRegistry;

class ScreenshotCatalogueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        PanelRegistry::register(new PanelDescriptor(
            key:           'admin',
            panelId:       'admin',
            domain:        env('ADMIN_DOMAIN'),
            loginHeading:  'Admin Login',
            email:         env('SCREENSHOT_ADMIN_EMAIL'),
            password:      env('SCREENSHOT_ADMIN_PASSWORD'),
            authenticator: static function (): void {
                $user = \App\Models\User::where('email', config('catalogue.admin_email'))->first();
                if ($user !== null) auth('web')->setUser($user);
            },
        ));
    }
}
```

Add the provider to `bootstrap/providers.php`. **If you installed the package as `require-dev`** (the default suggestion above), wrap the registration in a `class_exists` check so production — where the package isn't installed — silently skips it instead of crashing on a missing class import:

```php
// bootstrap/providers.php

$providers = [
    // ...your usual providers...
];

if (class_exists(\Visualbuilder\FilamentScreenshotCatalogue\PanelRegistry::class)) {
    $providers[] = App\Providers\ScreenshotCatalogueServiceProvider::class;
}

return $providers;
```

If you installed as a regular `require` dependency, register the provider unconditionally.

**Tenanted panels** (Filament's tenancy enabled): set the active tenant inside `authenticator`:

```php
authenticator: static function (): void {
    $user = \App\Models\OrganisationUser::where('email', 'sample@example.com')->first();
    if ($user !== null) {
        auth('organisation_user')->setUser($user);
        Filament::setTenant($user->organisation);
    }
},
```

## Configuration

Override defaults by publishing `config/screenshot-catalogue.php`. Common knobs:

```php
return [
    // S3 disk (must be configured in config/filesystems.php).
    'disk' => 's3_public',

    // Top-level S3 prefix.
    'path_prefix' => 'screenshots',

    // Capture viewports — name → { name, width, height }. Override to
    // standardise on your own breakpoints.
    'viewports' => [
        'desktop' => ['name' => 'desktop', 'width' => 1280, 'height' => 800],
        'tablet'  => ['name' => 'tablet',  'width' => 768,  'height' => 1024],
        'mobile'  => ['name' => 'mobile',  'width' => 375,  'height' => 812],
    ],

    // CSS injected into every page just before the screenshot fires —
    // use this to lock host theme animations into a stable state. Empty
    // by default.
    //
    // Example for a theme that animates its topbar on root scroll:
    //
    // 'capture_time_css' => '
    //     .fi-topbar { padding-block: 1rem !important; }
    //     .fi-main { padding-top: 7rem !important; }
    // ',
    'capture_time_css' => '',

    // Sitemap slugs captured with the main sidebar collapsed to its icon
    // rail, for wide pages such as a mail client. Wildcards follow
    // Str::is() ('mail*'). Needs sidebarCollapsibleOnDesktop() on the
    // panel; desktop widths only. `--sidebar=collapsed|expanded` on
    // screenshot:capture overrides it for one run.
    'collapsed_sidebar_pages' => [],

    // Sitemap entries to omit from the catalogue. Match the `slug` field
    // in `storage/app/sitemap-{panel}.json` — Filament's `{resource}.{page}`
    // form (e.g. `users.index`, `orders.edit`) for resource pages, or the
    // page class slug for custom pages. NOT URL slugs.
    'excluded_slugs' => [],

    // Brand assets uploaded next to index.html so the catalogue's heading
    // shows your wordmark + favicon. Both are optional.
    'brand' => [
        'name'    => 'Panel Screenshots',
        'logo'    => public_path('media/your_logo_dark_mode.svg'),
        'favicon' => public_path('media/your_favicon.svg'),
    ],
];
```

## How the catalogue is laid out on S3

```
{prefix}/{env}/{panel}/{version}/                       (env omitted in production)
├── index.html
├── favicon.svg
├── logo.svg
├── dashboard/
│   ├── desktop-light.png
│   ├── desktop-dark.png
│   ├── tablet-light.png
│   ├── tablet-dark.png
│   ├── mobile-light.png
│   └── mobile-dark.png
├── orders.index/
│   └── …
└── …
```

Tag-versioning: `--tag=latest` overwrites in place (continuous capture), any other tag (e.g. `--tag=v2.5.0`) is treated as immutable.

## Commands

| Command | Use it for |
|---|---|
| `panel:sitemap --panel=KEY` | Generate `sitemap-{panelId}.json` for the panel. Re-run when routes change. |
| `screenshot:dispatch --panel=KEY --tag=latest` | Queue one `CapturePageScreenshotsJob` per sitemap entry plus a final `RebuildScreenshotIndexJob`. Use a queue worker. |
| `screenshot:capture --panel=KEY --page=SLUG --tag=latest` | Synchronous run, single page or a few. Useful for debug. |
| `screenshot:capture ... --sidebar=collapsed` | Same, with the main sidebar collapsed (or `expanded`) on every page, whatever `collapsed_sidebar_pages` says. |
| `screenshot:rebuild-index --panel=KEY --tag=latest` | Re-render `index.html` from the existing PNGs without re-capturing. |
| `screenshot:url --url=URL --output=FILE` | One stand-alone URL (a signed public page, a marketing site), no panel registry and no S3 upload. See *Single URLs* below. |

All four panel commands accept `--panel=KEY` where `KEY` is the friendly CLI name registered in the descriptor (e.g. `admin`). The package translates to Filament's internal panel ID for URL/route resolution.

## Capture options

### Light and dark mode

Pick the colour scheme with `--mode`. It is repeatable, and each mode produces its own `{viewport}-{mode}.png`.

```bash
php artisan screenshot:capture --panel=admin --page=dashboard --mode=dark
php artisan screenshot:capture --panel=admin --page=dashboard --mode=light --mode=dark
php artisan screenshot:dispatch --panel=admin --mode=dark          # dark only for the whole panel
```

| Command | Default modes |
|---|---|
| `screenshot:dispatch` | `light` and `dark` |
| `screenshot:capture` | `light` only |

How a mode is applied: before the page is reloaded and captured, the runner sets `localStorage.theme` to `light` or `dark` and toggles the `dark` class on `<html>`. That is the same switch Filament's own theme toggle uses, so the panel renders its real dark styles. The panel must have dark mode enabled (`->darkMode()`, on by default in Filament). If your theme switches colour schemes some other way, publish the runner (`--tag=filament-screenshot-catalogue-js`) and change `setMode()` in `capture.mjs`.

### Viewports

`--viewport=desktop|tablet|mobile`, repeatable. `screenshot:dispatch` captures all three by default and `screenshot:capture` captures `desktop` only. The sizes come from the `viewports` config.

### Other options

| Option | Commands | What it does |
|---|---|---|
| `--page=SLUG` | `capture`, `dispatch` | Limit the run to these sitemap slugs (repeatable). |
| `--tag=TAG` | `capture`, `dispatch` | Version segment of the S3 path. `latest` overwrites; any other tag is an immutable run. |
| `--full-height` | `capture`, `dispatch` | Capture the whole scrollable page instead of the viewport. |
| `--sidebar=collapsed\|expanded` | `capture` | Force the main sidebar state on every page, overriding `collapsed_sidebar_pages`. |
| `--no-upload` | `capture` | Keep the PNGs local; skip S3 and `index.html`. |
| `--axe` | `capture`, `url` | Run an axe-core WCAG 2.1 AA audit on each shot and write `<shot>.axe.json` beside the PNG. Needs `npm i -D @axe-core/playwright` in the host. A failed audit never fails the screenshot. |

## Single URLs

`screenshot:url` captures one URL across one or more viewports, optionally logging in through a plain form first (the selectors default to WordPress's `wp-login.php`).

```bash
php artisan screenshot:url --url="https://app.example.com/quotes/12?signature=…" --output=storage/app/quote.png --viewport=desktop --viewport=mobile
```

It captures in the page's own colour scheme; there is no `--mode`. For safety it only opens `http` and `https` URLs on the host of `app.url` (or a subdomain of it), a registered panel domain, or a host matching `url_allowed_hosts`:

```php
'url_allowed_hosts' => ['*.*.example.com', 'www.example-marketing.com'],   // Str::is() patterns
```

Anything else, including `file://` URLs and internal addresses, is refused before the browser starts. The same check applies to `--login-url`.

## What's *not* in the package (host responsibilities)

- The seeded sample user(s) per panel — the package needs a real user to log in as, but creating one is host territory.
- Theme-specific tweaks beyond `capture_time_css` (e.g. permanent CSS overrides for a custom design system) — keep those in your theme stylesheet.
- Authentication wiring (tenants, magic links, 2FA dismissal) — the descriptor's `authenticator` closure is the seam.

## Production safety

The package refuses to run `screenshot:capture` and `screenshot:dispatch` when `APP_ENV=production`. The dispatch flow respects `APP_DEBUG=true` too — it bails out so the Laravel debug bar doesn't pollute every shot.

## License

GPL-2.0-or-later.
