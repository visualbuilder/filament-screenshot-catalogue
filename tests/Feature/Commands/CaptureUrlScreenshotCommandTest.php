<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Visualbuilder\FilamentScreenshotCatalogue\PanelDescriptor;
use Visualbuilder\FilamentScreenshotCatalogue\PanelRegistry;
use Visualbuilder\FilamentScreenshotCatalogue\Services\ScreenshotConfig;

beforeEach(function (): void {
    config(['app.url' => 'https://app.example.test', 'screenshot-catalogue.url_allowed_hosts' => ['*.partner.test']]);
    PanelRegistry::register(new PanelDescriptor(
        key: 'admin',
        panelId: 'admin',
        domain: 'admin.other.test',
        loginHeading: 'Admin Login',
        email: 'pen@tester.com',
        password: 'secret',
    ));
});

it('allows the app host, its subdomains, panel domains and configured hosts', function (string $url): void {
    expect(ScreenshotConfig::urlAllowed($url))->toBeTrue();
})->with([
    'https://app.example.test/quote/1?signature=abc',
    'http://tenant.app.example.test/po/2',
    'https://admin.other.test/login',
    'https://shop.partner.test/',
]);

it('refuses other schemes and hosts', function (string $url): void {
    expect(ScreenshotConfig::urlAllowed($url))->toBeFalse();
})->with([
    'file:///opt/app/.env',
    'javascript:alert(1)',
    'http://169.254.169.254/latest/meta-data/',
    'https://evil.example.com/',
    'https://app.example.test.evil.com/',
    'https://notapp.example.test/',
    'not a url',
]);

it('matches two-level wildcard patterns', function (): void {
    config(['screenshot-catalogue.url_allowed_hosts' => ['*.*.neurohub.uk', '*.*.hubdev.uk']]);

    expect(ScreenshotConfig::urlAllowed('https://acme.staging.neurohub.uk/quote/1'))->toBeTrue()
        ->and(ScreenshotConfig::urlAllowed('https://admin.dev.hubdev.uk/'))->toBeTrue()
        ->and(ScreenshotConfig::urlAllowed('https://neurohub.uk.evil.com/'))->toBeFalse()
        ->and(ScreenshotConfig::urlAllowed('https://evil-neurohub.uk/'))->toBeFalse();
});

it('refuses to capture a URL outside the allowlist', function (): void {
    expect(Artisan::call('screenshot:url', ['--url' => 'file:///opt/app/.env']))->toBe(1);
    expect(Artisan::output())->toContain('--url is not an allowed URL');
});

it('refuses a login URL outside the allowlist', function (): void {
    expect(Artisan::call('screenshot:url', [
        '--url' => 'https://app.example.test/',
        '--login-url' => 'https://evil.example.com/login',
        '--username' => 'a',
        '--password' => 'b',
    ]))->toBe(1);
    expect(Artisan::output())->toContain('--login-url is not an allowed URL');
});

describe('--axe', function (): void {
    beforeEach(function (): void {
        config(['screenshot-catalogue.capture_url_script' => __DIR__ . '/../../Fixtures/fake-capture-url.mjs']);
        $this->tmp = sys_get_temp_dir() . '/fake-capture-url-' . uniqid();
        mkdir($this->tmp);
        $this->manifestPath = $this->tmp . '/manifest.json';
        putenv('FAKE_CAPTURE_MANIFEST=' . $this->manifestPath);
        $_ENV['FAKE_CAPTURE_MANIFEST'] = $this->manifestPath;
    });

    afterEach(function (): void {
        array_map('unlink', glob($this->tmp . '/*') ?: []);
        @rmdir($this->tmp);
        unset($_ENV['FAKE_CAPTURE_MANIFEST']);
        putenv('FAKE_CAPTURE_MANIFEST');
    });

    it('passes axe => true and prints the audit path when --axe is given', function (): void {
        expect(Artisan::call('screenshot:url', ['--url' => 'https://app.example.test', '--output' => $this->tmp . '/shot.png', '--axe' => true]))->toBe(0);

        expect(json_decode(file_get_contents($this->manifestPath), true)['axe'])->toBeTrue();
        expect(Artisan::output())->toContain(' axe: ' . $this->tmp . '/shot.axe.json');
    });

    it('appends .axe.json when the output has no .png extension', function (): void {
        expect(Artisan::call('screenshot:url', ['--url' => 'https://app.example.test', '--output' => $this->tmp . '/shot', '--axe' => true]))->toBe(0);

        expect(Artisan::output())->toContain(' axe: ' . $this->tmp . '/shot.axe.json');
    });

    it('passes axe => false by default', function (): void {
        expect(Artisan::call('screenshot:url', ['--url' => 'https://app.example.test', '--output' => $this->tmp . '/shot.png']))->toBe(0);

        expect(json_decode(file_get_contents($this->manifestPath), true)['axe'])->toBeFalse();
        expect(Artisan::output())->not->toContain('axe:');
    });
})->skip(fn () => (new \Symfony\Component\Process\ExecutableFinder)->find('node') === null, 'node is required');
