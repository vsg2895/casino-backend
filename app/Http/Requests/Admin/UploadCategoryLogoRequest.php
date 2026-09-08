<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An SVG logo for a casino category.
 *
 * SEPARATE from {@see UploadMediaRequest} because the two want opposite things:
 * that one accepts rasters and converts them to WebP, which would destroy the
 * one property a category logo needs — staying sharp at both chip size and card
 * size in the same page.
 *
 * `mimetypes` and not `mimes`, and no `image` rule: Laravel's `image` rule
 * rejects SVG by default (it is not a bitmap), and `mimes:svg` trusts the
 * extension. The bytes are sanitised after this passes — see
 * {@see \App\Support\Media\SvgSanitizer}.
 *
 * 256 KB is generous for an icon and small enough that a mis-uploaded
 * illustration is refused here rather than shipped to every visitor.
 */
class UploadCategoryLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:image/svg+xml,text/xml,text/plain', 'max:256'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.mimetypes' => 'Category logos must be SVG files.',
            'file.max'       => 'The logo must be 256 KB or smaller.',
        ];
    }
}
