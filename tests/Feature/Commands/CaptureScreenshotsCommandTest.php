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

describe('--axe', function (): void {
    beforeEach(function (): void {
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
        @mkdir(storage_path('app'), 0777, true);
        file_put_contents(storage_path('app/sitemap-endUser.json'), '[{"slug":"dashboard","url":"/"}]');
        config(['screenshot-catalogue.capture_script' => __DIR__ . '/../../Fixtures/fake-capture.mjs']);
        $this->manifestPath = sys_get_temp_dir() . '/fake-capture-manifest-' . uniqid() . '.json';
        putenv('FAKE_CAPTURE_MANIFEST=' . $this->manifestPath);
        $_ENV['FAKE_CAPTURE_MANIFEST'] = $this->manifestPath;
    });

    afterEach(function (): void {
        @unlink(storage_path('app/sitemap-endUser.json'));
        @unlink($this->manifestPath);
        unset($_ENV['FAKE_CAPTURE_MANIFEST']);
        putenv('FAKE_CAPTURE_MANIFEST');
    });

    it('passes axe => true to the runner and lists the audit file when --axe is given', function (): void {
        expect(Artisan::call('screenshot:capture', ['--panel' => 'enduser', '--no-upload' => true, '--axe' => true]))->toBe(0);

        expect(json_decode(file_get_contents($this->manifestPath), true)['axe'])->toBeTrue();
        expect(Artisan::output())->toMatch('#axe: \S+dashboard/desktop-light\.axe\.json#');
    });

    it('passes axe => false by default', function (): void {
        expect(Artisan::call('screenshot:capture', ['--panel' => 'enduser', '--no-upload' => true]))->toBe(0);

        expect(json_decode(file_get_contents($this->manifestPath), true)['axe'])->toBeFalse();
        expect(Artisan::output())->not->toContain('axe:');
    });
})->skip(fn () => (new \Symfony\Component\Process\ExecutableFinder)->find('node') === null, 'node is required');
