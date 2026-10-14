# Panel Screenshot Catalogue

Drive the Filament panel screenshot catalogue from the host's terminal. Captures every page of a panel × every viewport × every mode, uploads to S3, generates a browsable index. Useful for visual QA, design review, marketing assets, and as the data source for AI-driven visual regression.

Every command takes `--panel=KEY` where `KEY` matches a registered `PanelDescriptor`. The host registers panels in a service provider — see `app/Providers/ScreenshotCatalogueServiceProvider.php` (or the package's README for the registration pattern).

## End-to-end flow

```bash
# 1. Generate the panel's sitemap (one JSON entry per navigable URL).
php artisan panel:sitemap --panel=admin

# 2. Queue per-page capture jobs.
php artisan screenshot:dispatch --panel=admin --tag=latest

# 3. Once jobs drain, the index lives at:
#    s3://{disk}/{prefix}/{env}/{panel}/{tag}/index.html
```

The package fans out one `CapturePageScreenshotsJob` per sitemap entry plus a final `RebuildScreenshotIndexJob` via Bus::batch with `allowFailures()` — so a single Playwright timeout doesn't kill the whole run, and the index always rebuilds against whatever shots actually landed.

## Commands

| Command | Use it for |
|---|---|
| `panel:sitemap --panel=KEY` | Generate `storage/app/sitemap-{panelId}.json` with one entry per navigable URL (resources, custom pages, auth screens). Re-run when routes change. |
| `screenshot:capture --panel=KEY --page=SLUG --tag=latest` | Synchronous one-page run for debugging. Bypasses the queue. |
| `screenshot:capture --panel=KEY --page=SLUG --axe` | Also runs an axe-core WCAG 2.1 AA audit per shot; writes `<shot>.axe.json` beside the PNG. |
| `screenshot:url --url=URL --output=FILE --axe` | The same for one URL; the audit goes to FILE with `.png` replaced by `.axe.json`. |
| `screenshot:dispatch --panel=KEY --tag=latest` | Queue per-page capture jobs + final index rebuild. The right command for full panel runs. |
| `screenshot:rebuild-index --panel=KEY --tag=latest` | Re-render `index.html` against the existing PNGs without re-capturing. Use after editing the catalogue's Blade template. |

### Filtering

```bash
# Capture only specific sitemap entries.
php artisan screenshot:dispatch --panel=admin --page=dashboard --page=orders.index --tag=latest

# Restrict viewports / modes.
php artisan screenshot:dispatch --panel=admin --viewport=mobile --mode=dark --tag=latest
```

### Tagging

`--tag=latest` overwrites in place — ideal for continuous capture against a moving HEAD. Any other tag (e.g. `--tag=v2.5.0`, `--tag=2026-05-02`) is treated as immutable in the catalogue's S3 layout.

## Output layout

```
{prefix}/{env}/{panel}/{tag}/                   (env omitted in production)
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

## Pre-flight checklist

Before dispatching a run, make sure:

1. **Sitemap is fresh**: `php artisan panel:sitemap --panel=KEY`. Re-run after route changes.
2. **`APP_DEBUG=false`** on the env you're capturing from — otherwise the Laravel debug bar renders in every shot.
3. **Queue worker is draining the right queue** (see `screenshot:dispatch --queue=...` if your app uses a non-default).
4. **Playwright is installed** in the project: `npx playwright install chromium`.
5. **The S3 disk** (`config('screenshot-catalogue.disk')`, default `s3_public`) is configured.
6. **`--axe` needs @axe-core/playwright** in the host project: `npm i -D @axe-core/playwright`.

## Adding a new panel

In your host project — typically a small service provider:

```php
use Visualbuilder\FilamentScreenshotCatalogue\PanelDescriptor;
use Visualbuilder\FilamentScreenshotCatalogue\PanelRegistry;

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
```

Tenanted panels: set `Filament::setTenant(...)` inside the closure too.

## Troubleshooting

- **`Unknown panel: foo`** — no descriptor registered for that key. Confirm the service provider runs at boot and that the descriptor's `key` matches what you're passing on the CLI.
- **Sitemap entry's URL 404s in the capture** — the `findSampleRecord` lookup didn't find a record owned by the auth user. Check the `authenticator` closure actually logs the user in (and sets the tenant for tenanted panels).
- **Topbar / sidebar animations look mid-state** — set `screenshot-catalogue.capture_time_css` to lock theme animations. Example:
  ```php
  'capture_time_css' => '
      .fi-topbar { padding-block: 1rem !important; }
      .fi-main { padding-top: 7rem !important; }
  ',
  ```
- **Index rebuilds slowly** — the rebuilder lists the whole S3 prefix; if you have many tags, consider scoping with `--tag=latest`.
