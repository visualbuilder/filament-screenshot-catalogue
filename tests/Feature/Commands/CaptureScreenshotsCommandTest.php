<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('refuses to run in production', function (): void {
    app()->detectEnvironment(static fn () => 'production');

    expect(Artisan::call('screenshot:capture', ['--panel' => 'enduser']))
        ->toBe(1);

    expect(Artisan::output())->toContain('Refusing to run in production');
});

it('refuses to run with APP_DEBUG enabled', function (): void {
    app()->detectEnvironment(static fn () => 'local');
    config(['app.debug' => true]);

    expect(Artisan::call('screenshot:capture', ['--panel' => 'enduser']))
        ->toBe(1);

    expect(Artisan::output())->toContain('APP_DEBUG=true');
});

it('returns FAILURE when the panel is not registered', function (): void {
    app()->detectEnvironment(static fn () => 'local');
    config(['app.debug' => false]);

    expect(Artisan::call('screenshot:capture', ['--panel' => 'mystery']))
        ->toBe(1);

    expect(Artisan::output())->toContain('Unknown panel: mystery');
});

it('rejects an unknown --sidebar value', function (): void {
    app()->detectEnvironment(static fn () => 'local');
    config(['app.debug' => false]);
    \Visualbuilder\FilamentScreenshotCatalogue\PanelRegistry::register(new \Visualbuilder\FilamentScreenshotCatalogue\PanelDescriptor(
        key: 'enduser',
        panelId: 'endUser',
        domain: 'enduser.example.test',
        loginHeading: 'End User Login',
        email: 'pen@tester.com',
        password: 'secret',
    ));

    expect(Artisan::call('screenshot:capture', ['--panel' => 'enduser', '--sidebar' => 'wide']))
        ->toBe(1);

    expect(Artisan::output())->toContain('Unknown --sidebar value: wide');
});
