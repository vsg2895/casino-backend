<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Models\CasinoReview;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A visitor submitting a review.
 *
 * Everything here is untrusted input from an open form on a public site, so the
 * rules are tight rather than generous:
 *
 *  - `body` has a MINIMUM length. A one-word review is almost always either spam
 *    or noise, and the floor costs a genuine reviewer nothing.
 *  - `author_email` is optional and never published. Asking for it and then
 *    showing it would be the mistake; not asking at all loses the only handle a
 *    moderator has on a repeat spammer.
 *  - `status` and `published_at` are NOT accepted. A submitter cannot publish
 *    their own review — the controller sets the status, and that is the whole
 *    moderation guarantee.
 */
class StoreCasinoReviewRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'author_name'  => ['required', 'string', 'min:2', 'max:120'],
            'author_email' => ['nullable', 'email:rfc', 'max:255'],
            'rating'       => ['required', 'integer', 'min:' . CasinoReview::MIN_RATING, 'max:' . CasinoReview::MAX_RATING],
            'title'        => ['nullable', 'string', 'max:160'],
            'body'         => ['required', 'string', 'min:20', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.min'       => 'Please write a little more — at least 20 characters.',
            'rating.min'     => 'Give a rating between 1 and 5.',
            'rating.max'     => 'Give a rating between 1 and 5.',
            'author_name.required' => 'Tell us what name to show with your review.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Trim before validating, so " " does not pass `required` and then store
        // as an empty author name.
        $this->merge([
            'author_name' => is_string($this->input('author_name')) ? trim($this->input('author_name')) : $this->input('author_name'),
            'title'       => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
            'body'        => is_string($this->input('body')) ? trim($this->input('body')) : $this->input('body'),
        ]);
    }
}
