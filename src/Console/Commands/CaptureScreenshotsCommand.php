<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Visualbuilder\FilamentScreenshotCatalogue\PanelRegistry;
use Visualbuilder\FilamentScreenshotCatalogue\Services\IndexBuilderService;
use Visualbuilder\FilamentScreenshotCatalogue\Services\ScreenshotConfig;

/**
 * Synchronous panel-wide visual catalogue capture. Walks the configured
 * sitemap, drives Playwright through every page × viewport × mode, then
 * uploads to S3 and rebuilds the index. Use this for ad-hoc local debug
 * runs; for full panel runs prefer `screenshot:dispatch`, which fans out
 * one queued job per page so a single timeout doesn't bring the run down.
 */
class CaptureScreenshotsCommand extends Command
{
    protected $signature = 'screenshot:capture
        {--panel=enduser : The Filament panel key (must be registered via PanelRegistry).}
        {--page=* : Sitemap slug to limit capture to (repeatable). Default: every entry in the sitemap.}
        {--mode=* : Mode to capture (light|dark). Default: light only.}
        {--viewport=* : Viewport to capture (desktop|tablet|mobile). Default: desktop only.}
        {--tag=latest : Version segment for the S3 path. "latest" overwrites; pass a tag (e.g. v2.5.0) for an immutable run.}
        {--no-upload : Capture locally only — skip S3 upload and index.html.}
        {--full-height : Capture the full scrollable document instead of just the viewport.}
        {--sidebar= : collapsed|expanded for every page in this run. Default: collapsed for slugs in screenshot-catalogue.collapsed_sidebar_pages, expanded otherwise.}';

    protected $description = 'Capture a visual catalogue of a Filament panel — pages × viewports × modes — synchronously.';

    public function handle(IndexBuilderService $indexBuilder): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production. Capture targets staging/dev only.');

            return self::FAILURE;
        }

        if (config('app.debug')) {
            $this->error('APP_DEBUG=true renders the Laravel debug bar inside every page. Set APP_DEBUG=false in .env (and clear config cache) before running screenshot:capture.');

            return self::FAILURE;
        }

        $panelKey = $this->option('panel');
        $credentials = ScreenshotConfig::panelCredentials($panelKey);
        if ($credentials === null) {
            $supported = implode(', ', PanelRegistry::keys()) ?: '(none registered yet)';
            $this->error("Unknown panel: {$panelKey}. Registered: {$supported}");

            return self::FAILURE;
        }

        $sidebar = $this->option('sidebar');
        if ($sidebar !== null && ! in_array($sidebar, ['collapsed', 'expanded'], true)) {
            $this->error("Unknown --sidebar value: {$sidebar}. Use collapsed or expanded.");

            return self::FAILURE;
        }

        $pages = ScreenshotConfig::withSidebarState($this->resolvePages((array) $this->option('page'), $panelKey), $sidebar);
        $viewports = ScreenshotConfig::resolveViewports((array) $this->option('viewport'));
        $modes = ScreenshotConfig::resolveModes((array) $this->option('mode'));

        $runId = now()->format('Ymd-His');
        $outDir = storage_path("app/screenshots/{$runId}");

        $manifest = [
            'domain' => $credentials['domain'],
            'loginPath' => '/login',
            'loginHeading' => $credentials['loginHeading'],
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'outDir' => $outDir,
            'viewports' => $viewports,
            'modes' => $modes,
            'pages' => $pages,
            // Banners that should appear pre-dismissed in screenshots.
            'dismissBannerIds' => ScreenshotConfig::dismissibleBannerIds(),
            // Optional CSS injected before each capture so host themes can
            // lock scroll-driven animations into a stable state.
            'captureTimeCss' => ScreenshotConfig::captureTimeCss(),
            'fullPage' => (bool) $this->option('full-height'),
        ];

        $this->info("Capturing {$panelKey}: " . count($pages) . ' page(s) × '
            . count($viewports) . ' viewport(s) × ' . count($modes) . ' mode(s)');
        $this->line("Output → {$outDir}");

        $script = ScreenshotConfig::captureScript();
        $process = new Process(['node', $script], base_path(), null, json_encode($manifest), 600);
        $process->mustRun(function ($type, $buffer): void {
            $this->getOutput()->write($buffer);
        });

        $result = json_decode($process->getOutput(), true) ?: [];
        $captured = $result['captured'] ?? [];

        $this->newLine();
        $this->info('Captured ' . count($captured) . ' shot(s)');

        if ($this->option('no-upload')) {
            foreach ($captured as $shot) {
                $this->line("  {$shot['path']}");
            }

            return self::SUCCESS;
        }

        $env = ScreenshotConfig::resolveEnv();
        $version = $this->option('tag');
        $panel = ScreenshotConfig::panelInternalId($panelKey);

        $this->processAndUpload($captured, env: $env, panelKey: $panelKey, panelId: $panel, version: $version);

        $indexUrl = $indexBuilder->rebuild($panelKey, $env, $version);

        $this->newLine();
        $this->info('Uploaded ' . count($captured) . ' shot(s) + index');
        $this->line("Index: {$indexUrl}");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{slug: string, viewport: string, mode: string, path: string}>  $captured
     */
    private function processAndUpload(array $captured, string $env, string $panelKey, string $panelId, string $version): void
    {
        $diskName = ScreenshotConfig::disk();
        $disk = Storage::disk($diskName);

        $this->newLine();
        $this->getOutput()->write('Uploading: ');

        foreach ($captured as $shot) {
            $key = ScreenshotConfig::s3Key($env, $panelId, $version, $shot['slug'], "{$shot['viewport']}-{$shot['mode']}.png");
            $contents = (string) file_get_contents($shot['path']);
            $disk->put($key, $contents, ['ContentType' => 'image/png']);

            // Mirror the per-shot event from PageCaptureService so the
            // sync `screenshot:capture` CLI path also feeds incremental
            // listeners (e.g. screenshot-review's
            // CreateScreenshotCaptureRow). Without this only the queued
            // dispatch path produces DB rows live.
            event(new \Visualbuilder\FilamentScreenshotCatalogue\Events\ScreenshotCaptured(
                panelKey: $panelKey,
                panelId: $panelId,
                slug: $shot['slug'],
                viewport: $shot['viewport'],
                mode: $shot['mode'],
                env: $env,
                version: $version,
                disk: $diskName,
                key: $key,
                etag: md5($contents),
                size: strlen($contents),
                url: $disk->url($key),
            ));

            $this->getOutput()->write('.');
        }

        $this->newLine();
    }

    /**
     * Pull the page list from the panel's sitemap. The sitemap is the
     * canonical source of truth for "every navigable URL in this panel"
     * — generated by `panel:sitemap`.
     *
     * @param  array<int, string>  $filterSlugs
     * @return array<int, array{slug: string, url: string, label: string, auth: string}>
     */
    private function resolvePages(array $filterSlugs, string $panelKey): array
    {
        $panelInternalId = ScreenshotConfig::panelInternalId($panelKey);
        $sitemapPath = storage_path("app/sitemap-{$panelInternalId}.json");

        if (! file_exists($sitemapPath)) {
            $this->warn("Sitemap not found at {$sitemapPath} — run `panel:sitemap --panel={$panelInternalId}` first.");

            return [];
        }

        $pages = collect(json_decode((string) file_get_contents($sitemapPath), true) ?: [])
            ->map(fn (array $entry): array => [
                'slug' => $entry['slug'],
                'url' => $entry['url'],
                'label' => $entry['label'] ?? $entry['slug'],
                'auth' => $entry['auth'] ?? 'authenticated',
            ]);

        if (empty($filterSlugs)) {
            return $pages->values()->all();
        }

        return $pages
            ->filter(fn (array $page): bool => in_array($page['slug'], $filterSlugs, true))
            ->values()
            ->all();
    }
}
