<?php

declare(strict_types=1);

namespace PhpMarkdown\Sanitizer;

/**
 * Single URL safety policy for every href/src the library emits: Markdown links,
 * images, reference definitions and autolinks (InlineParser) and raw-HTML attributes
 * (HtmlSanitizer).
 *
 * Callers pass the URL as the browser will see it, i.e. with Markdown escapes and
 * HTML entities already decoded.
 */
final class UrlValidator
{
    /** Schemes allowed in links and images ('' = relative URL). */
    public const SAFE_SCHEMES = ['http', 'https', 'mailto', ''];

    /**
     * Schemes that execute code or render attacker-controlled documents. Autolinks
     * keep CommonMark's "any scheme" rule (ftp:, irc:, …) except these.
     */
    public const SCRIPT_SCHEMES = ['javascript', 'vbscript', 'data'];

    /**
     * Whether $url is safe in an href/src attribute.
     *
     * - C0 controls and DEL are rejected, raw or percent-encoded: browsers strip
     *   CR/LF/TAB from URLs, which would otherwise allow "java\nscript:".
     * - Leading/trailing spaces are trimmed before the scheme check, as browsers do
     *   (" javascript:" is a javascript: URL). Inner spaces are allowed.
     * - Protocol-relative (//host) and backslash-prefixed URLs are rejected.
     * - The scheme must be in SAFE_SCHEMES, for the URL and its percent-decoded form.
     */
    public static function isSafe(string $url): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', rawurldecode($url))) {
            return false;
        }
        // Defence in depth: judge both the URL and its percent-decoded form
        // ("%6Aavascript:" is not a scheme for browsers, but is rejected anyway).
        return self::hasSafeScheme($url) && self::hasSafeScheme(rawurldecode($url));
    }

    private static function hasSafeScheme(string $url): bool
    {
        $url = trim($url, ' ');
        if (str_starts_with($url, '//') || str_starts_with($url, '\\')) {
            return false;
        }
        $parts = parse_url(str_replace(' ', '%20', $url));
        if ($parts === false) {
            return false;
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        return in_array($scheme, self::SAFE_SCHEMES, true);
    }

    /** Whether an autolink URL ("<scheme:…>") uses a script-capable scheme. */
    public static function hasScriptScheme(string $url): bool
    {
        $colon = strpos($url, ':');
        if ($colon === false) {
            return false;
        }
        return in_array(strtolower(trim(substr($url, 0, $colon), ' ')), self::SCRIPT_SCHEMES, true);
    }
}
