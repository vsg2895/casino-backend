<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

/** Same rules as creation; the slug's uniqueness ignores the author itself. */
class UpdateAuthorRequest extends StoreAuthorRequest {}
