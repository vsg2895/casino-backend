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
 */
class StoreArticleRequest extends FormRequest
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

    /**
     * No article is created live without a body: it would be a 404 on the site
     * (Article::scopeVisible) while the admin listed it as published.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('active') && trim(strip_tags((string) $this->input('body'))) === '') {
                    $validator->errors()->add('active', 'Add the article body before publishing it.');
                }
            },
            // A news post is created indexable only when it already clears the
            // bar in NewsIndexGate; otherwise it must start as noindex.
            function (Validator $validator): void {
                $site = $this->route('site');

                if ($this->query('type') !== Article::TYPE_NEWS || ! $site instanceof Site || $this->boolean('noindex')) {
                    return;
                }

                $problems = NewsIndexGate::problems($this->input('body'), $site, $this->input('editor_note'));

                if ($problems !== []) {
                    $validator->errors()->add('noindex', 'Turn "Keep out of search results" on, or fix: ' . implode('; ', $problems) . '.');
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
