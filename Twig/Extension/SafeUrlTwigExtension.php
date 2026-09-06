<?php

namespace Yosimitso\WorkingForumBundle\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Neutralises script-bearing URLs in HTML produced by the markdown filter.
 *
 * Post::setContent() runs strip_tags() over what the user typed, so an author
 * cannot store a literal <script> or an onerror attribute. That check happens
 * before markdown runs, though, and markdown's whole job is to *create* HTML
 * from plain text - so markup that contains no tags at all on the way in comes
 * back out as a tag:
 *
 *     [click me](javascript:alert(document.domain))
 *
 * survives strip_tags() untouched (there is no tag in it), survives the
 * htmlentities()/html_entity_decode() round trip the entity does, and is then
 * rendered as <a href="javascript:alert(document.domain)">click me</a>. Every
 * template that shows a post finishes the chain with |raw, so that anchor
 * reaches the page intact: stored XSS, fired for every reader of the thread.
 * ![x](javascript:...) does the same through an image's src.
 *
 * This filter runs after markdown and rewrites the href/src of anything whose
 * scheme can execute. It deliberately does not try to be a general HTML
 * sanitiser - strip_tags() upstream already means the only tags present are the
 * ones markdown itself generated, and the only dangerous thing markdown can
 * generate is a URL.
 */
class SafeUrlTwigExtension extends AbstractExtension
{
    /**
     * Schemes that execute rather than locate.
     *
     * data: is included because data:text/html renders as a document with
     * script in it, and vbscript: for the same reason as javascript: on the
     * engines that still honour it.
     */
    private const DANGEROUS_SCHEMES = ['javascript', 'vbscript', 'data'];

    /**
     * What a neutralised URL becomes. '#' keeps the anchor clickable-looking
     * and inert, rather than removing the element and silently changing the
     * shape of somebody's post.
     */
    private const NEUTRALISED = '#';

    /**
     * @return array
     */
    public function getFilters()
    {
        return [
            new TwigFilter(
                'safe_url',
                [$this, 'safeUrl'],
                ['is_safe' => ['html']]
            ),
        ];
    }

    /**
     * @param string|null $html
     *
     * @return string
     */
    public function safeUrl($html)
    {
        if ($html === null || $html === '') {
            return (string) $html;
        }

        return preg_replace_callback(
            '/\b(href|src)\s*=\s*(["\'])(.*?)\2/is',
            function (array $match) {
                [, $attribute, $quote, $value] = $match;

                if ($this->isDangerous($value)) {
                    return $attribute . '=' . $quote . self::NEUTRALISED . $quote;
                }

                return $match[0];
            },
            $html
        );
    }

    /**
     * Whether a URL's scheme is one that executes.
     *
     * The comparison is made on a normalised copy, because the scheme can be
     * disguised and browsers still resolve it:
     *
     *   - HTML entities: &#106;avascript: decodes to javascript: when the
     *     browser parses the attribute, so the raw bytes never spell it out.
     *   - Whitespace and C0 control characters: browsers strip tab, newline,
     *     carriage return and NUL from inside a scheme, so "java\tscript:"
     *     runs.
     *   - Leading whitespace before the scheme, and letter case.
     *
     * Anything with no colon before the first '/', '?' or '#' is a relative
     * URL and has no scheme to object to.
     *
     * @param string $value
     *
     * @return bool
     */
    private function isDangerous($value)
    {
        $normalised = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalised = preg_replace('/[\x00-\x20]+/', '', $normalised);
        $normalised = strtolower((string) $normalised);

        $colon = strpos($normalised, ':');

        if ($colon === false) {
            return false;
        }

        $delimiter = strcspn($normalised, '/?#');

        if ($colon > $delimiter) {
            // The colon sits inside a path or query, e.g. /a/b:c - not a scheme.
            return false;
        }

        return in_array(substr($normalised, 0, $colon), self::DANGEROUS_SCHEMES, true);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'safe_url';
    }
}
