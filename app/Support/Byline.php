<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Author;
use DateTimeInterface;

/**
 * "Written by / Reviewed by · Updated <date>" as one public payload.
 *
 * Shared by casinos, articles and CMS pages so the three can never disagree
 * about what a byline reveals. A hidden author is treated exactly like no
 * author — callers load only visible ones, and this re-checks in case one did
 * not — so a profile that is still being written never reaches a page through a
 * byline.
 *
 * Null when there is nobody to name. A date with no person behind it is not a
 * byline: "Updated <date>" alone would imply someone checked the page.
 */
final class Byline
{
    /**
     * @return array{author: ?array<string, ?string>, reviewer: ?array<string, ?string>, last_reviewed_at: ?string}|null
     */
    public static function from(?Author $author, ?Author $reviewer, ?DateTimeInterface $lastReviewedAt): ?array
    {
        $author = $author?->visible ? $author : null;
        $reviewer = $reviewer?->visible ? $reviewer : null;

        if ($author === null && $reviewer === null) {
            return null;
        }

        return [
            'author'           => $author?->toByline(),
            'reviewer'         => $reviewer?->toByline(),
            // A string, never Carbon: these payloads are cached by SiteCache.
            'last_reviewed_at' => $lastReviewedAt?->format(DateTimeInterface::ATOM),
        ];
    }
}
