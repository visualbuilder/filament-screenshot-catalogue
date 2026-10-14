<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Services;

use Illuminate\Support\Str;
use Visualbuilder\FilamentScreenshotCatalogue\PanelRegistry;

/**
 * Shared configuration helpers for the screenshot catalogue: panel
 * credentials, viewport definitions, env→path mapping, and the canonical
 * S3 key builder. Used by every command and queued job in the package.
 *
 * Panel credentials come from `PanelRegistry` — hosts register their
 * panels via `PanelRegistry::register(new PanelDescriptor(...))`. Viewport
 * defs and S3 path conventions come from `config('screenshot-catalogue')`.
 */
class ScreenshotConfig
{
    /**
     * @return array{domain: string, loginHeading: string, email: string, password: string}|null
     */
    public static function panelCredentials(string $panelKey): ?array
    {
        $descriptor = PanelRegistry::get($panelKey);
        if ($descriptor === null) {
            return null;
        }

        return [
            'domain' => $descriptor->domain,
            'loginHeading' => $descriptor->loginHeading,
            'email' => $descriptor->email,
            'password' => $descriptor->password,
        ];
    }

    /**
     * Translate the friendly CLI panel key (lowercase) to the panel ID
     * Filament uses internally (often camelCase).
     */
    public static function panelInternalId(string $key): string
    {
        return PanelRegistry::get($key)?->panelId ?? $key;
    }

    /**
     * Viewport definitions are config-driven so each host can standardise
     * on its own breakpoints without forking the package.
     *
     * @return array<string, array{name: string, width: int, height: int}>
     */
    public static function viewportDefinitions(): array
    {
        $configured = config('screenshot-catalogue.viewports');

        if (! is_array($configured) || $configured === []) {
            return [
                'desktop' => ['name' => 'desktop', 'width' => 1280, 'height' => 800],
                'tablet' => ['name' => 'tablet', 'width' => 768, 'height' => 1024],
                'mobile' => ['name' => 'mobile', 'width' => 375, 'height' => 812],
            ];
        }

        return $configured;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, array{name: string, width: int, height: int}>
     */
    public static function resolveViewports(array $names): array
    {
        $defs = self::viewportDefinitions();
        if (empty($names)) {
            return [$defs[array_key_first($defs)]];
        }

        return collect($names)
            ->map(fn (string $key) => $defs[strtolower($key)] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $modes
     * @return array<int, string>
     */
    public static function resolveModes(array $modes): array
    {
        if (empty($modes)) {
            return ['light'];
        }

        return collect($modes)
            ->map(fn (string $mode) => strtolower($mode))
            ->filter(fn (string $mode) => in_array($mode, ['light', 'dark'], true))
            ->values()
            ->all();
    }

    public static function resolveEnv(): string
    {
        return match ($env = app()->environment()) {
            'local' => 'dev',
            default => $env,
        };
    }

    /**
     * Canonical S3 path builder.
     * Layout: {prefix}/{env}/{panel}/{version}/{slug}/{file}
     * Production omits {env}; root index.html omits {slug}.
     */
    public static function s3Key(string $env, string $panel, string $version, string $slug, string $file): string
    {
        $prefix = (string) config('screenshot-catalogue.path_prefix', 'screenshots');
        $segments = [$prefix];

        if ($env !== 'production') {
            $segments[] = $env;
        }
        $segments[] = $panel;
        $segments[] = $version;
        if ($slug !== '') {
            $segments[] = $slug;
        }
        $segments[] = $file;

        return implode('/', $segments);
    }

    public static function s3KeyPrefix(string $env, string $panel, string $version): string
    {
        return self::s3Key($env, $panel, $version, '', '');
    }

    public static function disk(): string
    {
        $value = config('screenshot-catalogue.disk');

        return is_string($value) && $value !== '' ? $value : 's3_public';
    }

    /**
     * Banner IDs to pre-dismiss (pushed into the page's `localStorage`
     * before the screenshot fires) so that visualbuilder/filament-2fa's
     * banners don't pollute every shot. Optional dependency — when the
     * package isn't installed, the lookup is a no-op.
     *
     * @return array<int, int>
     */
    public static function dismissibleBannerIds(): array
    {
        $bannerModel = '\\Visualbuilder\\Filament2fa\\Models\\Banner';
        if (! class_exists($bannerModel)) {
            return [];
        }

        return $bannerModel::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Optional CSS string injected into every captured page just before
     * the screenshot fires — used to lock host-specific scroll-driven
     * states into something stable. Empty by default.
     */
    public static function captureTimeCss(): string
    {
        return (string) config('screenshot-catalogue.capture_time_css', '');
    }

    /**
     * Give each page a `sidebar` state of 'collapsed' or 'expanded': the
     * override when one is passed, else 'collapsed' for slugs matching
     * `screenshot-catalogue.collapsed_sidebar_pages`. A page that already
     * carries a state keeps it unless there is an override.
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array<string, mixed>>
     */
    public static function withSidebarState(array $pages, ?string $override = null): array
    {
        $patterns = (array) config('screenshot-catalogue.collapsed_sidebar_pages', []);

        return array_map(function (array $page) use ($override, $patterns): array {
            $page['sidebar'] = $override
                ?? $page['sidebar']
                ?? (Str::is($patterns, (string) ($page['slug'] ?? '')) ? 'collapsed' : 'expanded');

            return $page;
        }, $pages);
    }

    /**
     * Resolve the path to the Playwright runner script. Hosts can publish
     * the script (via `vendor:publish --tag=filament-screenshot-catalogue-js`)
     * and override `screenshot-catalogue.capture_script` if they want
     * to customise it.
     */
    public static function captureScript(): string
    {
        $configured = config('screenshot-catalogue.capture_script');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return dirname(__DIR__, 2) . '/resources/js/capture.mjs';
    }

    /**
     * Resolve the path to the single-URL Playwright runner used by
     * `screenshot:url`. Override via `screenshot-catalogue.capture_url_script`.
     */
    public static function captureUrlScript(): string
    {
        $configured = config('screenshot-catalogue.capture_url_script');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return dirname(__DIR__, 2) . '/resources/js/capture-url.mjs';
    }
}
