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
     * id of the temporary element the fragment is parsed under. Never reaches
     * the output: only this element's children are serialised.
     */
    private const ROOT_ID = 'wf-safe-url-root';

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

        $document = new \DOMDocument('1.0', 'UTF-8');

        // Markdown output is a fragment with several top-level blocks - a
        // blockquote and the reply under it, say - and libxml has no portable
        // way to load one.
        //
        // LIBXML_HTML_NOIMPLIED looks like the answer and is a trap: on older
        // libxml builds it treats the first top-level element as the document
        // root and reparents the ones after it underneath, so a reply ends up
        // *inside* the quote it answers. This package declares no PHP version
        // constraint, so it can be installed on exactly those builds, and the
        // corruption would be silent.
        //
        // Wrapping the fragment in one element sidesteps the whole question:
        // with a single root there is nothing to reparent, no flag is needed,
        // and the wrapper's children are serialised back out - so the html and
        // body libxml implies around it are never emitted either.
        //
        // The XML declaration is what tells libxml the bytes are UTF-8; without
        // it it assumes ISO-8859-1 and every accented character in a Spanish
        // post comes back mangled.
        //
        // libxml reports unknown tags and the like through the global error
        // buffer; silence it around the load so a stray warning from someone's
        // post does not surface, and restore whatever the app had set.
        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="' . self::ROOT_ID . '">' . $html . '</div>',
            LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $loaded ? $this->wrapper($document) : null;

        if ($root === null) {
            // Should not happen - loadHTML recovers from almost anything - but
            // returning the input unscrubbed would hand back exactly the markup
            // this filter exists to defuse. Fall back to the conservative
            // textual scrub: it can over-neutralise, and never under-neutralises.
            return $this->scrubText($html);
        }

        foreach ($this->attributeNodes($document) as $attribute) {
            if ($this->isDangerous($attribute->value)) {
                $attribute->value = self::NEUTRALISED;
            }
        }

        $result = '';

        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }

    /**
     * Every href/src attribute node in the parsed document.
     *
     * Going through the parser rather than a regular expression is the whole
     * point: `href="..."` occurring as *text* - which is exactly what a post
     * explaining this attack contains, and what Markdown emits for an inline
     * `code` span - is not an attribute, and must be left alone. The regular
     * expression this replaced could not tell the two apart, so a post
     * documenting the attack had its example silently rewritten, and its
     * lazy `.*?` could span tags and swallow rendered content between two
     * quotes.
     *
     * @param \DOMDocument $document
     *
     * @return \DOMAttr[]
     */
    private function attributeNodes(\DOMDocument $document)
    {
        $xpath = new \DOMXPath($document);
        $nodes = [];

        foreach ($xpath->query('//@href | //@src') as $attribute) {
            $nodes[] = $attribute;
        }

        return $nodes;
    }

    /**
     * The temporary wrapper the fragment was parsed under.
     *
     * Located by id rather than by assuming a position, so an unexpected shape
     * from libxml yields null - and the caller falls back to the textual scrub -
     * rather than silently serialising the wrong subtree.
     *
     * @param \DOMDocument $document
     *
     * @return \DOMElement|null
     */
    private function wrapper(\DOMDocument $document)
    {
        $xpath = new \DOMXPath($document);
        $found = $xpath->query('//*[@id="' . self::ROOT_ID . '"]');

        if ($found === false || $found->length === 0) {
            return null;
        }

        return $found->item(0);
    }

    /**
     * Conservative textual fallback for the case the parser gives up entirely.
     *
     * @param string $html
     *
     * @return string
     */
    private function scrubText($html)
    {
        return preg_replace_callback(
            '/\b(href|src)\s*=\s*(["\'])([^"\']*)\2/i',
            function (array $match) {
                if ($this->isDangerous($match[3])) {
                    return $match[1] . '=' . $match[2] . self::NEUTRALISED . $match[2];
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
