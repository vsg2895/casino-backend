<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

/**
 * Editing a template.
 *
 * Everything is inherited: {@see StoreSmsTemplateRequest} already resolves the
 * route binding in its uniqueness rule, so renaming a template to its own current
 * name is not an error. Nothing here differs, and keeping it as a subclass means
 * the two forms cannot drift apart — unlike the credential requests, there is no
 * secret to preserve on a blank edit.
 */
class UpdateSmsTemplateRequest extends StoreSmsTemplateRequest {}
