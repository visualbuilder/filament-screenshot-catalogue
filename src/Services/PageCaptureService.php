<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Visualbuilder\FilamentScreenshotCatalogue\Events\ScreenshotCaptured;

/**
 * Spawns the Playwright runner for one or more pages and uploads each
 * captured PNG to the public S3 bucket. Used by both the synchronous
 * Artisan command and the per-page queue job — they differ only in
 * how many pages are passed in at once.
 */
class PageCaptureService
{
    /**
     * Capture every viewport × mode for the given page list and return
     * the uploaded shots (including their S3 URLs).
     *
     * @param  array<int, array{slug: string, url: string, label?: string, auth?: string}>  $pages
     * @param  array<int, array{name: string, width: int, height: int}>  $viewports
     * @param  array<int, string>  $modes
     * @return array<int, array{slug: string, viewport: string, mode: string, path: string, url: string}>
     */
    public function capture(string $panelKey, array $pages, array $viewports, array $modes, string $env, string $version, ?string $runId = null, bool $fullPage = false): array
    {
        if (empty($pages)) {
            return [];
        }

        $credentials = ScreenshotConfig::panelCredentials($panelKey);
        if ($credentials === null) {
            throw new RuntimeException("Unknown panel: {$panelKey}");
        }

        $runId ??= now()->format('Ymd-His-') . str()->random(4);
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
            'pages' => ScreenshotConfig::withSidebarState($pages),
            'dismissBannerIds' => ScreenshotConfig::dismissibleBannerIds(),
            'captureTimeCss' => ScreenshotConfig::captureTimeCss(),
            'fullPage' => $fullPage,
        ];

        $script = ScreenshotConfig::captureScript();
        $process = new Process(['node', $script], base_path(), null, json_encode($manifest), 600);
        $process->mustRun();

        $result = json_decode($process->getOutput(), true) ?: [];
        $captured = $result['captured'] ?? [];

        return $this->uploadShots(
            $captured,
            $env,
            $panelKey,
            ScreenshotConfig::panelInternalId($panelKey),
            $version,
        );
    }

    /**
     * @param  array<int, array{slug: string, viewport: string, mode: string, path: string}>  $captured
     * @return array<int, array{slug: string, viewport: string, mode: string, path: string, url: string}>
     */
    private function uploadShots(array $captured, string $env, string $panelKey, string $panelId, string $version): array
    {
        $diskName = ScreenshotConfig::disk();
        $disk = Storage::disk($diskName);
        $shots = [];

        foreach ($captured as $shot) {
            $contents = (string) file_get_contents($shot['path']);
            $key = ScreenshotConfig::s3Key($env, $panelId, $version, $shot['slug'], "{$shot['viewport']}-{$shot['mode']}.png");
            $disk->put($key, $contents, ['ContentType' => 'image/png']);

            $url = $disk->url($key);

            // Fire per-shot event so optional listeners (e.g. the
            // screenshot-review package) can ingest the capture into
            // their DB incrementally — reviewers see the captures grid
            // populate as the batch progresses, instead of waiting for
            // the batch's `finally` sync at the end.
            event(new ScreenshotCaptured(
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
                url: $url,
            ));

            $shots[] = [
                ...$shot,
                'url' => $url,
            ];
        }

        return $shots;
    }
}
