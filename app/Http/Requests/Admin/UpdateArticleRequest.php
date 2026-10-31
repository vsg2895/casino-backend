<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Article;
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
            ],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body'    => ['nullable', 'string'],
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
            'news_category_id' => ['nullable', 'integer', 'exists:news_categories,id'],
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

                if (! $article instanceof Article
                    || ! $article->isIngested()
                    || $article->active
                    || ! $this->boolean('active')
                    || trim(strip_tags((string) $this->input('body'))) !== '') {
                    return;
                }

                $validator->errors()->add(
                    'active',
                    'This news draft has not been rewritten yet. Run php artisan news:rewrite first, then approve it.',
                );
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex'  => 'Use lowercase words separated by hyphens, e.g. bonus-terms-explained.',
            'slug.unique' => 'This site already has a guide with that slug.',
        ];
    }
}
