<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Mail\EmailTemplateCatalog;
use Illuminate\Http\JsonResponse;

/**
 * Read-only list of the email templates that can be rendered for a site.
 *
 * Backs the admin "Email Template" dropdown. Sourced from
 * {@see EmailTemplateCatalog}, so a newly registered template appears in the UI
 * with no change here.
 */
class EmailTemplateTypeController extends Controller
{
    public function index(EmailTemplateCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->types()]);
    }
}
