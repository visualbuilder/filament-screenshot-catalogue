<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

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
    expect(Artisan::call('screenshot:url', ['--url' => 'https://example.test', '--output' => $this->tmp . '/shot.png', '--axe' => true]))->toBe(0);

    expect(json_decode(file_get_contents($this->manifestPath), true)['axe'])->toBeTrue();
    expect(Artisan::output())->toContain(' axe: ' . $this->tmp . '/shot.axe.json');
})->skip(fn () => (new \Symfony\Component\Process\ExecutableFinder)->find('node') === null, 'node is required');

it('appends .axe.json when the output has no .png extension', function (): void {
    expect(Artisan::call('screenshot:url', ['--url' => 'https://example.test', '--output' => $this->tmp . '/shot', '--axe' => true]))->toBe(0);

    expect(Artisan::output())->toContain(' axe: ' . $this->tmp . '/shot.axe.json');
})->skip(fn () => (new \Symfony\Component\Process\ExecutableFinder)->find('node') === null, 'node is required');

it('passes axe => false by default', function (): void {
    expect(Artisan::call('screenshot:url', ['--url' => 'https://example.test', '--output' => $this->tmp . '/shot.png']))->toBe(0);

    expect(json_decode(file_get_contents($this->manifestPath), true)['axe'])->toBeFalse();
    expect(Artisan::output())->not->toContain('axe:');
})->skip(fn () => (new \Symfony\Component\Process\ExecutableFinder)->find('node') === null, 'node is required');
