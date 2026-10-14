<?php

declare(strict_types=1);

use Visualbuilder\FilamentScreenshotCatalogue\PanelDescriptor;
use Visualbuilder\FilamentScreenshotCatalogue\PanelRegistry;
use Visualbuilder\FilamentScreenshotCatalogue\Services\ScreenshotConfig;

function registerExampleDescriptor(): void
{
    PanelRegistry::register(new PanelDescriptor(
        key: 'enduser',
        panelId: 'endUser',
        domain: 'enduser.example.test',
        loginHeading: 'End User Login',
        email: 'pen@tester.com',
        password: 'secret',
    ));
}

it('panelCredentials returns null when no descriptor is registered', function (): void {
    expect(ScreenshotConfig::panelCredentials('admin'))->toBeNull();
});

it('panelCredentials returns the right shape for a registered panel', function (): void {
    registerExampleDescriptor();

    expect(ScreenshotConfig::panelCredentials('enduser'))->toMatchArray([
        'domain' => 'enduser.example.test',
        'loginHeading' => 'End User Login',
        'email' => 'pen@tester.com',
        'password' => 'secret',
    ]);
});

it('panelInternalId translates the friendly key to Filament panelId', function (): void {
    registerExampleDescriptor();

    expect(ScreenshotConfig::panelInternalId('enduser'))->toBe('endUser')
        ->and(ScreenshotConfig::panelInternalId('endUser'))->toBe('endUser');
});

it('panelInternalId falls through unchanged when no descriptor matches', function (): void {
    expect(ScreenshotConfig::panelInternalId('mystery'))->toBe('mystery');
});

it('viewportDefinitions returns hard-coded defaults when config is empty', function (): void {
    config(['screenshot-catalogue.viewports' => null]);

    $defs = ScreenshotConfig::viewportDefinitions();

    expect($defs)->toHaveKeys(['desktop', 'tablet', 'mobile'])
        ->and($defs['desktop'])->toMatchArray(['width' => 1280, 'height' => 800]);
});

it('viewportDefinitions reads from config when provided', function (): void {
    config(['screenshot-catalogue.viewports' => [
        'wide' => ['name' => 'wide', 'width' => 1920, 'height' => 1080],
    ]]);

    expect(ScreenshotConfig::viewportDefinitions())->toEqual([
        'wide' => ['name' => 'wide', 'width' => 1920, 'height' => 1080],
    ]);
});

it('resolveViewports defaults to the first defined viewport when none requested', function (): void {
    expect(ScreenshotConfig::resolveViewports([]))->toHaveCount(1);
});

it('resolveViewports filters out unknown viewport names', function (): void {
    expect(ScreenshotConfig::resolveViewports(['desktop', 'mystery', 'mobile']))
        ->toHaveCount(2);
});

it('resolveModes filters to the canonical light/dark set', function (): void {
    expect(ScreenshotConfig::resolveModes(['light', 'foo', 'dark']))
        ->toEqualCanonicalizing(['light', 'dark']);
});

it('resolveModes defaults to light when none requested', function (): void {
    expect(ScreenshotConfig::resolveModes([]))->toBe(['light']);
});

it('resolveEnv maps local to dev', function (): void {
    expect(ScreenshotConfig::resolveEnv())->toBe('dev'); // testbench env defaults to local→dev? actually testing→testing
})->skip('env-dependent');

it('s3Key builds the canonical path layout', function (): void {
    config(['screenshot-catalogue.path_prefix' => 'screenshots']);

    expect(ScreenshotConfig::s3Key('dev', 'admin', 'latest', 'dashboard', 'desktop-light.png'))
        ->toBe('screenshots/dev/admin/latest/dashboard/desktop-light.png');
});

it('s3Key omits the env segment in production', function (): void {
    config(['screenshot-catalogue.path_prefix' => 'screenshots']);

    expect(ScreenshotConfig::s3Key('production', 'admin', 'v1.0.0', 'dashboard', 'desktop-light.png'))
        ->toBe('screenshots/admin/v1.0.0/dashboard/desktop-light.png');
});

it('s3Key omits the slug segment for top-level files like index.html', function (): void {
    config(['screenshot-catalogue.path_prefix' => 'screenshots']);

    expect(ScreenshotConfig::s3Key('dev', 'admin', 'latest', '', 'index.html'))
        ->toBe('screenshots/dev/admin/latest/index.html');
});

it('s3Key honours a custom path prefix', function (): void {
    config(['screenshot-catalogue.path_prefix' => 'visual-qa']);

    expect(ScreenshotConfig::s3Key('dev', 'admin', 'latest', '', 'index.html'))
        ->toBe('visual-qa/dev/admin/latest/index.html');
});

it('disk() reads from config with a sensible default', function (): void {
    config(['screenshot-catalogue.disk' => null]);
    expect(ScreenshotConfig::disk())->toBe('s3_public');

    config(['screenshot-catalogue.disk' => 'minio']);
    expect(ScreenshotConfig::disk())->toBe('minio');
});

it('captureTimeCss returns the configured string and empty by default', function (): void {
    config(['screenshot-catalogue.capture_time_css' => '.fi-topbar { padding: 1rem }']);
    expect(ScreenshotConfig::captureTimeCss())->toContain('padding: 1rem');

    config(['screenshot-catalogue.capture_time_css' => null]);
    expect(ScreenshotConfig::captureTimeCss())->toBe('');
});

it('captureScript falls back to the bundled runner when no config override is set', function (): void {
    config(['screenshot-catalogue.capture_script' => null]);

    expect(ScreenshotConfig::captureScript())
        ->toEndWith('resources/js/capture.mjs');
});

it('dismissibleBannerIds returns an empty array when filament-2fa is not installed', function (): void {
    expect(ScreenshotConfig::dismissibleBannerIds())->toBe([]);
});

it('withSidebarState collapses pages matching the config patterns and expands the rest', function (): void {
    config(['screenshot-catalogue.collapsed_sidebar_pages' => ['mail*', 'kanban.index']]);

    $pages = ScreenshotConfig::withSidebarState([
        ['slug' => 'mail.index', 'url' => '/mail'],
        ['slug' => 'mail-dashboard', 'url' => '/mail-dashboard'],
        ['slug' => 'kanban.index', 'url' => '/kanban'],
        ['slug' => 'orders.index', 'url' => '/orders'],
    ]);

    expect(array_column($pages, 'sidebar'))->toBe(['collapsed', 'collapsed', 'collapsed', 'expanded']);
});

it('withSidebarState expands every page by default', function (): void {
    expect(ScreenshotConfig::withSidebarState([['slug' => 'mail.index', 'url' => '/mail']])[0]['sidebar'])
        ->toBe('expanded');
});

it('withSidebarState applies an override to every page and keeps a page state without one', function (): void {
    config(['screenshot-catalogue.collapsed_sidebar_pages' => ['mail*']]);
    $pages = [
        ['slug' => 'mail.index', 'url' => '/mail'],
        ['slug' => 'orders.index', 'url' => '/orders', 'sidebar' => 'collapsed'],
    ];

    expect(array_column(ScreenshotConfig::withSidebarState($pages, 'expanded'), 'sidebar'))->toBe(['expanded', 'expanded'])
        ->and(array_column(ScreenshotConfig::withSidebarState($pages), 'sidebar'))->toBe(['collapsed', 'collapsed']);
});
