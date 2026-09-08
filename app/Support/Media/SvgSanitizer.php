<?php

declare(strict_types=1);

namespace App\Support\Media;

/**
 * Strips everything executable out of an uploaded SVG.
 *
 * THIS IS A SECURITY CONTROL, not a tidiness pass. An SVG is an XML document
 * that browsers execute: `<script>`, `on*` handlers, `javascript:` URLs and
 * `<foreignObject>` all run when the file is opened directly. Uploads are served
 * from the API origin, so an unsanitised SVG would be stored XSS against that
 * origin — reachable by simply visiting the image URL.
 *
 * The front ends additionally render these through `<img src>`, where scripts do
 * not execute. That is defence in depth, not the fix: the file is still fetchable
 * at its own URL, so it has to be safe on its own.
 *
 * Allow-list by intent: an icon needs shapes, paths, groups and presentation
 * attributes. It never needs script, network access or embedded HTML.
 */
final class SvgSanitizer
{
    /** Elements removed with their entire subtree. */
    private const array FORBIDDEN_ELEMENTS = [
        'script', 'foreignObject', 'iframe', 'embed', 'object', 'link', 'meta',
        'audio', 'video', 'handler', 'listener', 'set', 'animate',
        'animateMotion', 'animateTransform', 'use',
    ];

    /**
     * Attributes removed wherever they appear.
     *
     * `href`/`xlink:href` go because an icon has no reason to reference anything
     * external, and both accept `javascript:`. `use` is dropped above for the
     * same reason — it is the element that dereferences them.
     */
    private const array FORBIDDEN_ATTRIBUTES = ['href', 'xlink:href', 'from', 'to', 'values', 'begin'];

    /**
     * @return string|null The cleaned SVG, or null when the input is not usable
     *                     SVG at all.
     */
    public static function clean(string $svg): ?string
    {
        $svg = trim($svg);

        if ($svg === '' || ! str_contains(strtolower($svg), '<svg')) {
            return null;
        }

        // Entity expansion is the other classic SVG attack (billion laughs, XXE).
        // A DOCTYPE has no place in an icon, so anything carrying one is refused
        // outright rather than repaired.
        if (preg_match('~<!DOCTYPE~i', $svg) === 1 || preg_match('~<!ENTITY~i', $svg) === 1) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        $document = new \DOMDocument();
        // LIBXML_NONET forbids any network fetch during parsing.
        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement === null) {
            return null;
        }

        if (strtolower($document->documentElement->nodeName) !== 'svg') {
            return null;
        }

        self::scrub($document->documentElement);

        $cleaned = $document->saveXML($document->documentElement);

        return is_string($cleaned) && $cleaned !== '' ? $cleaned : null;
    }

    /** Depth-first, removing forbidden nodes and attributes as it goes. */
    private static function scrub(\DOMElement $element): void
    {
        // Snapshot first: removing children while iterating a live DOMNodeList
        // skips siblings, which is how a sanitiser silently lets one through.
        $children = [];

        foreach ($element->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            if (in_array(strtolower($child->nodeName), array_map('strtolower', self::FORBIDDEN_ELEMENTS), true)) {
                $element->removeChild($child);

                continue;
            }

            self::scrub($child);
        }

        $attributes = [];

        foreach ($element->attributes ?? [] as $attribute) {
            $attributes[] = $attribute->nodeName;
        }

        foreach ($attributes as $name) {
            $lower = strtolower($name);
            $value = (string) $element->getAttribute($name);

            $isEventHandler = str_starts_with($lower, 'on');
            $isForbidden = in_array($lower, self::FORBIDDEN_ATTRIBUTES, true);
            // Catches `javascript:` and `data:text/html` however they are spaced
            // or cased, in any attribute that survived the checks above.
            $carriesCode = preg_match('~(javascript|data)\s*:~i', $value) === 1
                && ! preg_match('~^data:image/(png|jpeg|gif|webp);base64,~i', $value);

            if ($isEventHandler || $isForbidden || $carriesCode) {
                $element->removeAttribute($name);
            }
        }
    }
}
