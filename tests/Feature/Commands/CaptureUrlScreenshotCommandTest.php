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
