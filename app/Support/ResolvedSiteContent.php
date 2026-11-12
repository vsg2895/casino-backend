<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * A casino's or offer's content as one site publishes it.
 *
 * `metaTitle` / `metaDescription` are the finished values a page uses, an
 * offer's generated fallback included. `storedMetaTitle` /
 * `storedMetaDescription` are what a person actually typed (this site's, else
 * the default) and nothing generated: they feed the `seo` block, where a
 * generated string would wrongly outrank the site's own SEO pattern.
 */
final readonly class ResolvedSiteContent
{
    public function __construct(
        public ?string $description,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public ?string $storedMetaTitle,
        public ?string $storedMetaDescription,
        public bool $noindex,
        public ?CarbonInterface $updatedAt,
    ) {}
}
