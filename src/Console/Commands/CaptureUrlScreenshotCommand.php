<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Visualbuilder\FilamentScreenshotCatalogue\Services\ScreenshotConfig;

/**
 * Single-URL screenshot capture. Hits one URL across one or more
 * viewports, optionally logging in first via a generic form (selectors
 * default to WordPress wp-login.php but can be overridden), and writes
 * one PNG per viewport to disk. No S3 upload, no panel registry —
 * this is the ad-hoc cousin of `screenshot:capture`, meant for grabbing
 * a shot of a WordPress admin page or any other stand-alone URL.
 */
class CaptureUrlScreenshotCommand extends Command
{
    protected $signature = 'screenshot:url
        {--url= : The full URL to capture (required).}
        {--output= : Path template — the literal token "viewport" inside curly braces is replaced by the viewport name. If multiple viewports are passed without that token, the name is auto-injected before the extension. Default: storage/app/screenshots/single/<timestamp>-<viewport>.png.}
        {--login-url= : Login page URL. Omit to skip login.}
        {--username= : Login username/email.}
        {--password= : Login password.}
        {--username-selector=#user_login : CSS selector for the username field. Default targets WordPress wp-login.php.}
        {--password-selector=#user_pass : CSS selector for the password field.}
        {--submit-selector=#wp-submit : CSS selector for the submit button.}
        {--viewport=* : Viewport to capture — repeatable. Choices: desktop (1280x800), tablet (768x1024), mobile (375x812), or custom (single-shot, requires width/height). Default: desktop.}
        {--width= : Viewport width in pixels (used when viewport=custom).}
        {--height= : Viewport height in pixels (used when viewport=custom).}
        {--full-page : Capture the full scrollable page rather than just the viewport.}
        {--wait=1500 : Milliseconds to wait after navigation before the screenshot fires.}
        {--ignore-https-errors : Ignore TLS errors (useful for local self-signed certs).}
        {--axe : Run an axe-core WCAG 2.1 AA audit on each shot; writes <shot>.axe.json beside the PNG.}';

    protected $description = 'Capture a single URL across one or more viewports, optionally logging in first.';

    public function handle(): int
    {
        $url = (string) $this->option('url');
        if ($url === '') {
            $this->error('The --url option is required.');

            return self::FAILURE;
        }

        $loginUrl = (string) $this->option('login-url');
        $username = (string) $this->option('username');
        $password = (string) $this->option('password');
        $hasCredentials = $username !== '' && $password !== '';

        if ($loginUrl !== '' && ! $hasCredentials) {
            $this->error('--login-url was provided but --username/--password are missing.');

            return self::FAILURE;
        }
        if ($hasCredentials && $loginUrl === '') {
            $this->error('--username/--password were provided but --login-url is missing.');

            return self::FAILURE;
        }

        $viewports = $this->resolveViewports();
        if ($viewports === null) {
            return self::FAILURE;
        }

        $outputTemplate = (string) $this->option('output');
        if ($outputTemplate === '') {
            $outputTemplate = storage_path('app/screenshots/single/' . now()->format('Ymd-His') . '-{viewport}.png');
        }

        $shots = collect($viewports)
            ->map(fn (array $vp): array => [
                'viewport' => $vp,
                'output' => $this->resolveOutputPath($outputTemplate, $vp['name'], count($viewports) > 1),
            ])
            ->all();

        $manifest = [
            'url' => $url,
            'fullPage' => (bool) $this->option('full-page'),
            'waitMs' => (int) $this->option('wait'),
            'ignoreHttpsErrors' => (bool) $this->option('ignore-https-errors'),
            'login' => $hasCredentials ? [
                'url' => $loginUrl,
                'username' => $username,
                'password' => $password,
                'usernameSelector' => (string) $this->option('username-selector'),
                'passwordSelector' => (string) $this->option('password-selector'),
                'submitSelector' => (string) $this->option('submit-selector'),
            ] : null,
            'axe' => (bool) $this->option('axe'),
            'shots' => $shots,
        ];

        $this->info("Capturing {$url} across " . count($shots) . ' viewport(s)' . ($manifest['fullPage'] ? ' (full-page)' : ''));
        foreach ($shots as $shot) {
            $vp = $shot['viewport'];
            $this->line("  {$vp['name']} ({$vp['width']}×{$vp['height']}) → {$shot['output']}");
        }
        if ($hasCredentials) {
            $this->line("Login via {$loginUrl} as {$username}");
        }

        $script = ScreenshotConfig::captureUrlScript();
        // 60s base + 30s per shot — covers slow login + multi-viewport runs.
        $timeout = 60 + (count($shots) * 30);
        $process = new Process(['node', $script], base_path(), null, json_encode($manifest), $timeout);
        $process->mustRun(function ($type, $buffer): void {
            $this->getOutput()->write($buffer);
        });

        $this->newLine();
        $this->info('Saved ' . count($shots) . ' shot(s)');
        foreach ($shots as $shot) {
            $axe = str_ends_with(strtolower($shot['output']), '.png') ? substr($shot['output'], 0, -4) . '.axe.json' : $shot['output'] . '.axe.json';
            if ($this->option('axe') && is_file($axe)) {
                $this->line("  axe: {$axe}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{name: string, width: int, height: int}>|null
     */
    private function resolveViewports(): ?array
    {
        /** @var array<int, string> $names */
        $names = (array) $this->option('viewport');
        if (empty($names)) {
            $names = ['desktop'];
        }
        $names = array_map(fn (string $n): string => strtolower($n), $names);

        $hasCustom = in_array('custom', $names, true);
        if ($hasCustom && count($names) > 1) {
            $this->error('--viewport=custom cannot be combined with other viewports — pick custom alone (with --width/--height) or use named viewports.');

            return null;
        }

        if ($hasCustom) {
            $width = (int) $this->option('width');
            $height = (int) $this->option('height');
            if ($width <= 0 || $height <= 0) {
                $this->error('--viewport=custom requires --width and --height.');

                return null;
            }

            return [['name' => 'custom', 'width' => $width, 'height' => $height]];
        }

        $defs = ScreenshotConfig::viewportDefinitions();
        $resolved = [];
        foreach ($names as $name) {
            if (! isset($defs[$name])) {
                $supported = implode(', ', array_keys($defs));
                $this->error("Unknown viewport: {$name}. Supported: {$supported} (or 'custom' with --width/--height).");

                return null;
            }
            $resolved[] = $defs[$name];
        }

        return $resolved;
    }

    /**
     * Substitute {viewport} in the template. When the template has no
     * token but the user is capturing multiple viewports, auto-inject
     * the name before the extension so shots don't overwrite each other.
     */
    private function resolveOutputPath(string $template, string $viewportName, bool $multipleViewports): string
    {
        if (str_contains($template, '{viewport}')) {
            return str_replace('{viewport}', $viewportName, $template);
        }

        if (! $multipleViewports) {
            return $template;
        }

        $ext = pathinfo($template, PATHINFO_EXTENSION);
        $base = $ext === ''
            ? $template
            : substr($template, 0, -strlen($ext) - 1);

        return $ext === ''
            ? "{$base}-{$viewportName}"
            : "{$base}-{$viewportName}.{$ext}";
    }
}
