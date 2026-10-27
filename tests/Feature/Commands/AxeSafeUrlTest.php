<?php

declare(strict_types=1);

// The capture scripts stay standalone, so the helper is not exported. This test
// extracts the `safeUrl` line from each script and runs it in node.
dataset('capture scripts', ['capture.mjs', 'capture-url.mjs']);

it('masks the signature of a signed URL in the axe output', function (string $script): void {
    $source = file_get_contents(__DIR__ . '/../../../resources/js/' . $script);
    preg_match('/^const safeUrl = .*;$/m', $source, $match);
    expect($match)->not->toBeEmpty();

    $js = $match[0] . "\nconsole.log(JSON.stringify([safeUrl('https://x.test/a?id=1&signature=abc123def&expires=9'), safeUrl(new Error('goto https://x.test/a?signature=s3cret failed')), safeUrl('https://x.test/plain')]));";
    $out = shell_exec('node -e ' . escapeshellarg($js));
    $result = json_decode((string) $out, true);

    expect($result[0])->toBe('https://x.test/a?id=1&signature=***&expires=9')
        ->and($result[1])->not->toContain('s3cret')->toContain('signature=***')
        ->and($result[2])->toBe('https://x.test/plain');
})->with('capture scripts')
    ->skip(fn () => (new \Symfony\Component\Process\ExecutableFinder)->find('node') === null, 'node is required');

it('applies safeUrl to the url and error fields in both scripts', function (string $script): void {
    $source = file_get_contents(__DIR__ . '/../../../resources/js/' . $script);

    expect($source)->toContain('url: safeUrl(page.url())')
        ->toContain('error: safeUrl(err)')
        ->not->toContain('url: page.url()');
})->with('capture scripts');

it('skips frames that cannot run scripts in both scripts', function (string $script): void {
    $source = file_get_contents(__DIR__ . '/../../../resources/js/' . $script);

    expect($source)->toContain(<<<'JS'
const SCRIPTLESS_FRAMES = 'iframe[sandbox]:not([sandbox~="allow-scripts"])';
JS)->toContain('.exclude(SCRIPTLESS_FRAMES).analyze()');
})->with('capture scripts');
