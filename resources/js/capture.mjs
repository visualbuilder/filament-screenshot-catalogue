#!/usr/bin/env node
/**
 * Screenshot catalogue runner.
 *
 * Reads a JSON manifest from stdin describing pages to capture, logs into
 * the target panel once, then iterates pages × viewports × modes capturing
 * a PNG per variant to a local output directory. The PHP Artisan command
 * (`screenshot:capture`) drives this — never invoke directly unless
 * debugging.
 *
 * Manifest shape:
 *   {
 *     "domain": "my.neurohub.local",
 *     "loginPath": "/login",
 *     "loginHeading": "End User Login",
 *     "email": "...",
 *     "password": "password",
 *     "outDir": "/tmp/screenshots/<run>",
 *     "viewports": [{ "name": "desktop", "width": 1280, "height": 800 }, ...],
 *     "modes": ["light", "dark"],
 *     "pages": [{ "slug": "dashboard", "url": "/", "sidebar": "expanded" }, ...],
 *     "axe": false
 *   }
 *
 * axe: true runs an axe-core WCAG 2.1 AA audit on each shot and writes
 * <shot>.axe.json beside the PNG.
 *
 * Each capture is written to:
 *   {outDir}/{slug}/{viewport}-{mode}.png
 */

import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';
import { stdin } from 'node:process';

async function readStdin() {
    const chunks = [];
    for await (const chunk of stdin) chunks.push(chunk);
    return Buffer.concat(chunks).toString('utf8');
}

async function login(page, manifest) {
    const url = `https://${manifest.domain}${manifest.loginPath}`;
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.getByRole('textbox', { name: 'Email address*' }).fill(manifest.email);
    await page.getByRole('textbox', { name: 'Password*' }).fill(manifest.password);

    const before = page.url();
    await page.getByRole('button', { name: 'Sign in' }).click();

    // Wait for navigation away from /login. Generous timeout — under
    // high parallel-worker concurrency the host's php-fpm pool can queue
    // login submits, so 30s isn't enough; 90s lets the dev server
    // catch up without needing per-host config.
    await page.waitForURL((url) => url.toString() !== before, { timeout: 90000 });
}

async function setMode(page, mode) {
    // The pink26 panel reads `localStorage.theme` and toggles `.dark` on
    // <html> via the segmented theme-toggle component. Mirror that
    // mechanism so dark-mode shots render the dark CSS.
    await page.evaluate((mode) => {
        localStorage.setItem('theme', mode);
        document.documentElement.classList.toggle('dark', mode === 'dark');
    }, mode);
}

async function dismissBanners(page, bannerIds) {
    if (!Array.isArray(bannerIds) || bannerIds.length === 0) return;
    // The visualbuilder/filament-2fa banner stores dismissed IDs in
    // localStorage under `filament-banners::closed` as a JSON array.
    // The component's check is `parsedBanners.indexOf(this.bannerId)`
    // where bannerId is rendered as a string by Blade (`'{{ $banner->id }}'`),
    // so the stored IDs must be strings or indexOf returns -1 due to
    // strict equality and the banner stays visible.
    const idsAsStrings = bannerIds.map((id) => String(id));
    await page.evaluate((ids) => {
        localStorage.setItem('filament-banners::closed', JSON.stringify(ids));
    }, idsAsStrings);
}

async function setSidebar(page, viewport, state) {
    // Filament's sidebar store persists its open state with Alpine's
    // $persist under the keys `isOpen` and `isOpenDesktop` (JSON). Set both
    // on every page, not only collapsed ones: the authenticated context is
    // shared, so a collapsed page would otherwise leak into the next shot.
    // Below Filament's 1024px breakpoint the sidebar is an overlay, so the
    // state is left alone there.
    if (viewport.width < 1024) return;
    const open = state !== 'collapsed';
    await page.evaluate((open) => {
        localStorage.setItem('isOpen', JSON.stringify(open));
        localStorage.setItem('isOpenDesktop', JSON.stringify(open));
    }, open);
}

async function injectChromeHider(page) {
    // Belt-and-braces: even with APP_DEBUG=false the catalogue should
    // never include any dev tooling, debugbar, error overlays, or
    // skip-to-content links. Hide common offenders via CSS in case
    // something local renders them anyway.
    await page.addStyleTag({
        content: `
            .phpdebugbar, .phpdebugbar-header, .phpdebugbar-body,
            #phpdebugbar, .phpdebugbar-resizehandle,
            [class*="debugbar"],
            .skip-link, [href="#content"]
            { display: none !important; }
        `,
    });
}

function axePath(png) {
    return /\.png$/i.test(png) ? png.replace(/\.png$/i, '.axe.json') : png + '.axe.json';
}

// Runs an axe-core WCAG 2.1 AA audit on the live page and writes the
// violations beside the screenshot. A failed audit never fails the shot.
async function auditPage(page, out) {
    const path = axePath(out);
    try {
        const { default: AxeBuilder } = await import('@axe-core/playwright');
        const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        const summary = {
            url: page.url(),
            violations: results.violations.map((v) => ({
                id: v.id,
                impact: v.impact,
                help: v.help,
                helpUrl: v.helpUrl,
                nodes: v.nodes.map((n) => ({ target: n.target, html: n.html.slice(0, 500), failureSummary: n.failureSummary })),
            })),
            passes: results.passes.length,
            incomplete: results.incomplete.length,
        };
        await mkdir(dirname(path), { recursive: true });
        await writeFile(path, JSON.stringify(summary, null, 1));
    } catch (err) {
        console.error('axe-failed: ' + err);
        try {
            await mkdir(dirname(path), { recursive: true });
            await writeFile(path, JSON.stringify({ url: page.url(), error: String(err) }, null, 1));
        } catch (writeErr) {
            console.error('axe-failed: ' + writeErr);
        }
    }
    return path;
}

function normalisePath(path) {
    return path.replace(/\/+$/, '') || '/';
}

function redirectReason(path, manifest) {
    if (path === '/consent-option-request') return 'outstanding consent';
    if (path === normalisePath(manifest.loginPath ?? '/login') || path.endsWith('/login')) return 'login';
    if (/(^|\/)(profile|edit-profile|onboarding)$/.test(path)) return 'not onboarded';
    return 'unexpected redirect';
}

// Thrown when the browser lands on a different path from the target, so
// the run records the reason and moves on instead of saving the wrong page.
class RedirectedCapture extends Error {}

async function capturePage(context, manifest, target, viewport, mode) {
    const page = await context.newPage();
    await page.setViewportSize({ width: viewport.width, height: viewport.height });

    await page.goto(`https://${manifest.domain}${target.url}`, { waitUntil: 'domcontentloaded' });
    await setMode(page, mode);
    await dismissBanners(page, manifest.dismissBannerIds);
    await setSidebar(page, viewport, target.sidebar);
    await page.reload({ waitUntil: 'domcontentloaded' });

    const base = `https://${manifest.domain}`;
    const expected = normalisePath(new URL(target.url, base).pathname);
    const landed = normalisePath(new URL(page.url()).pathname);
    if (landed !== expected) {
        await page.close();
        throw new RedirectedCapture(`capture-redirected: ${target.slug} ${viewport.name}-${mode} redirected to ${landed} (${redirectReason(landed, manifest)})`);
    }

    // Filament hydrates over Livewire — wait for the page chrome and
    // give Alpine + any async requests a moment to settle so the shot
    // catches the rendered state, not a flash of unstyled content.
    // Tolerant: timing out shouldn't kill the run, just log it so the
    // captured screenshot still reflects whatever rendered.
    try {
        await page.waitForSelector('.fi-page, .fi-simple-layout, .fi-body', { timeout: 20000 });
    } catch (err) {
        console.error(`warn: ${target.slug} ${viewport.name}-${mode} did not render .fi-page selector — capturing as-is`);
    }
    await page.waitForTimeout(1500);

    const out = `${manifest.outDir}/${target.slug}/${viewport.name}-${mode}.png`;
    await mkdir(dirname(out), { recursive: true });

    // Audit before the chrome hider runs: it hides the skip link, which
    // axe must still see.
    const axe = manifest.axe ? await auditPage(page, out) : null;
    await injectChromeHider(page);

    // Optional capture-time CSS — used by host themes to lock scroll-driven
    // animations (e.g. shrinking topbars) into a stable state for the
    // catalogue. The string comes from `panel-screenshot-catalogue.capture_time_css`
    // on the host; empty by default.
    if (manifest.captureTimeCss) {
        await page.addStyleTag({ content: manifest.captureTimeCss });
        await page.waitForTimeout(150);
    }

    // Default: capture viewport-only — every shot in the catalogue should
    // represent what fits on a real device. Long tables/forms scrolling
    // off-screen is a deliberate UX signal we want to see, not a problem
    // the screenshot tool should hide by stitching the full document.
    // `manifest.fullPage` (set by --full-height) opts in to a stitched
    // full-document capture when reviewers explicitly want the whole page.
    await page.screenshot({ path: out, fullPage: Boolean(manifest.fullPage) });

    await page.close();
    return { out, axe };
}

(async () => {
    const manifest = JSON.parse(await readStdin());

    const browser = await chromium.launch({
        // Self-signed cert on local Valet domains; needed so HTTPS to
        // *.neurohub.local works without a real cert chain.
        ignoreHTTPSErrors: true,
    });
    const context = await browser.newContext({ ignoreHTTPSErrors: true });

    try {
        // Partition pages by auth state. Unauthenticated pages (login,
        // password reset, etc.) need to be captured BEFORE we authenticate
        // — otherwise visiting /login when already logged in just bounces
        // to the dashboard.
        const unauthPages = manifest.pages.filter((p) => p.auth === 'unauthenticated');
        const authPages = manifest.pages.filter((p) => (p.auth ?? 'authenticated') === 'authenticated');

        const captured = [];
        const redirected = [];

        // 1. Unauthenticated pages — fresh context PER SHOT, not just per
        // unauth page list. Filament's middleware on /login sets a
        // session cookie even for anonymous GETs; the second capture in
        // the same context (e.g. dark mode after light) then arrives
        // with that cookie and Filament's RedirectIfAuthenticated /
        // session-prelude flow treats it as a returning visitor and
        // bounces to the dashboard. Throwaway context per shot makes
        // every unauth capture genuinely first-visit.
        if (unauthPages.length > 0) {
            try {
                for (const target of unauthPages) {
                    for (const viewport of manifest.viewports) {
                        for (const mode of manifest.modes) {
                            const guestContext = await browser.newContext({ ignoreHTTPSErrors: true });
                            try {
                                try {
                                    const { out, axe } = await capturePage(guestContext, manifest, target, viewport, mode);
                                    captured.push({ slug: target.slug, viewport: viewport.name, mode, path: out, axe });
                                    console.error(`captured ${target.slug} ${viewport.name}-${mode} (guest)`);
                                } catch (err) {
                                    if (!(err instanceof RedirectedCapture)) throw err;
                                    console.error(err.message);
                                    redirected.push(err.message);
                                }
                            } finally {
                                await guestContext.close();
                            }
                        }
                    }
                }
            } catch (err) {
                console.error(`unauth capture loop error: ${err.message}`);
                throw err;
            }
        }

        // 2. Authenticated pages — login once, then reuse the session.
        if (authPages.length > 0) {
            const loginPage = await context.newPage();
            await login(loginPage, manifest);
            await loginPage.close();

            for (const target of authPages) {
                for (const viewport of manifest.viewports) {
                    for (const mode of manifest.modes) {
                        try {
                            const { out, axe } = await capturePage(context, manifest, target, viewport, mode);
                            captured.push({ slug: target.slug, viewport: viewport.name, mode, path: out, axe });
                            console.error(`captured ${target.slug} ${viewport.name}-${mode}`);
                        } catch (err) {
                            if (!(err instanceof RedirectedCapture)) throw err;
                            console.error(err.message);
                            redirected.push(err.message);
                        }
                    }
                }
            }
        }

        console.log(JSON.stringify({ captured }));
        // Exit 5 once the browser closes: some shots landed on a different
        // page and were not saved. exitCode (not exit()) lets stdout flush.
        if (redirected.length > 0) process.exitCode = 5;
    } finally {
        await context.close();
        await browser.close();
    }
})().catch((err) => {
    console.error(err.stack || err.message || err);
    process.exit(1);
});
