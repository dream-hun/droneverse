<?php

declare(strict_types=1);

/*
 * A preload is a promise that the document is about to need the file.
 *
 * Vite's `fonts:` option broke that promise for months. It fetched Inter from
 * Bunny, emitted a <link rel="preload"> per weight, and put the matching
 * @font-face rules in a stylesheet of its own — one that `input` never listed,
 * so the page linked app.css and nothing else. Three woff2 files, ~71KB, were
 * fetched on every page load and could never be used, because no rule on the
 * page named the family they belonged to. The app's real Inter comes from
 * `@import '@fontsource-variable/inter'` under a different family name.
 *
 * Nothing failed. The suite was green, the pages rendered in the right font,
 * and the only thing that ever said otherwise was a console warning in
 * Firefox: "preloaded with link preload was not used within a few seconds".
 *
 * So the rule is asserted here instead of left to whoever happens to open the
 * console: a preloaded file must be reachable from a stylesheet the page
 * actually links. That still permits a real preload — link the stylesheet that
 * uses it and this passes — while a preload for a file nothing can reach fails.
 */

test('every preloaded asset is referenced by a stylesheet the page links', function (): void {
    $html = (string)$this->get(route('home'))->assertOk()->getContent();

    preg_match_all('#<link[^>]*rel="preload"[^>]*>#i', $html, $preloads);
    preg_match_all('#<link[^>]*rel="stylesheet"[^>]*href="([^"]+)"[^>]*>#i', $html, $sheets);

    $localPath = fn(string $url): string => public_path(parse_url($url, PHP_URL_PATH) ?? '');

    $linked = '';
    foreach ($sheets[1] as $href) {
        $path = $localPath($href);

        if (is_file($path)) {
            $linked .= file_get_contents($path);
        }
    }

    foreach ($preloads[0] as $tag) {
        preg_match('#href="([^"]+)"#i', $tag, $href);
        preg_match('#as="([^"]+)"#i', $tag, $as);

        $file = basename(parse_url($href[1], PHP_URL_PATH) ?? '');

        if (($as[1] ?? '') === 'style') {
            expect($sheets[1])->toContain($href[1]);

            continue;
        }

        expect(str_contains($linked, $file))->toBeTrue(
            "$file is preloaded but no stylesheet the page links refers to it, so the browser fetches it and never uses it",
        );
    }
});
