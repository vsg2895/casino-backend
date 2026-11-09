<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Article;
use App\Models\DeletedNewsSlug;
use App\Models\Site;
use App\Services\News\NewsIndexGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A guide.
 *
 * `published_at` is the ONLY publication control: null is a draft, a past date
 * is live, a future date is scheduled. There is deliberately no separate
 * "published" boolean to contradict it.
 *
 * The slug is unique PER SITE — two domains may each have a
 * "bonus-terms-explained" — and is generated from the title when omitted.
 *
 * A scraped news draft cannot be switched ON until it has a body. Until
 * `news:rewrite` writes one, its title and excerpt are the source's own words,
 * and it carries `noindex`, so approving it would publish a page that is copied
 * text and that search engines are told to skip. Only the switch-on is
 * refused: a post that is already live can still be edited or hidden.
 */
class UpdateArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title'   => ['required', 'string', 'max:255'],
            'slug'    => [
                'nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('articles', 'slug')
                    ->where('site_id', $this->route('site')?->id)
                    ->ignore($this->route('article')?->id),
                // A deleted news post's URL answers 410 Gone; a new news post
                // must not take it, or the site would answer it 410 too.
                ...($this->query('type') === Article::TYPE_NEWS && DeletedNewsSlug::ready()
                    ? [Rule::unique('deleted_news_slugs', 'slug')->where('site_id', $this->route('site')?->id)]
                    : []),
            ],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body'    => ['nullable', 'string'],
            // Plain text; the page escapes it. Counts toward NewsIndexGate.
            'editor_note' => ['nullable', 'string', 'max:' . NewsIndexGate::EDITOR_NOTE_MAX],
            'hero_image_path' => ['nullable', 'string', 'max:500'],
            'published_at'    => ['nullable', 'date'],
            'meta_title'      => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'canonical_url'   => ['nullable', 'string', 'max:500', 'regex:#^https?://#'],
            'active'          => ['sometimes', 'boolean'],
            'featured'        => ['sometimes', 'boolean'],
            // The news page's "Most Popular" rail. Its own flag, not a
            // synonym for `featured` — see the migration.
            'to_be_most_popular' => ['sometimes', 'boolean'],
            'noindex'         => ['sometimes', 'boolean'],
            // Byline: authors of THIS site only.
            'author_id'        => ['nullable', 'integer', Rule::exists('authors', 'id')->where('site_id', $this->route('site')?->id)],
            'reviewed_by_id'   => ['nullable', 'integer', Rule::exists('authors', 'id')->where('site_id', $this->route('site')?->id)],
            'last_reviewed_at' => ['nullable', 'date'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $article = $this->route('article');

                // Only the step INTO public view. An article that is already
                // active keeps its existing edits and its "hide" possible.
                if (! $article instanceof Article || $article->active || ! $this->boolean('active')) {
                    return;
                }

                // The body the article will have AFTER this save: the one sent,
                // or the stored one when the request leaves it out (a list
                // toggle sends `active` alone, and used to be refused for a body
                // it never touched).
                $body = $this->has('body') ? $this->input('body') : $article->body;

                if (trim(strip_tags((string) $body)) !== '') {
                    return;
                }

                // Every article, not only feed items: a bodiless page is never
                // public (Article::scopeVisible), so approving one would publish
                // a 404 the admin reports as live.
                $validator->errors()->add(
                    'active',
                    $article->isIngested()
                        ? 'This news draft has not been rewritten yet. Run php artisan news:rewrite first, then approve it.'
                        : 'Add the article body before publishing it.',
                );
            },
            // Indexing a news post is gated on added value — see NewsIndexGate.
            // Only the step OUT of noindex: an already indexable post keeps
            // every edit and toggle possible.
            function (Validator $validator): void {
                $article = $this->route('article');
                $site = $this->route('site');

                if (! $article instanceof Article || ! $site instanceof Site || ! $article->isNews() || ! $article->noindex) {
                    return;
                }

                if (! $this->has('noindex') || $this->boolean('noindex')) {
                    return;
                }

                $problems = NewsIndexGate::problems(
                    $this->has('body') ? $this->input('body') : $article->body,
                    $site,
                    $this->has('editor_note') ? $this->input('editor_note') : $article->editor_note,
                );

                if ($problems !== []) {
                    $validator->errors()->add('noindex', 'Keep "Keep out of search results" on: ' . implode('; ', $problems) . '.');
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex'  => 'Use lowercase words separated by hyphens, e.g. bonus-terms-explained.',
            'slug.unique' => 'This site already has (or had, for a deleted news post) an article with that slug.',
        ];
    }
}
