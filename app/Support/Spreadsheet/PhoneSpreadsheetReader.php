<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use App\Support\Phone\PhoneNumber;

/**
 * Streams unique, E.164-normalised phone numbers out of an uploaded .xlsx / .csv.
 *
 * All the traversal work lives in {@see ColumnSpreadsheetReader}. This class only
 * says which headers name the column and what a valid number is — and the latter
 * defers to {@see PhoneNumber}, so an imported number is normalised by exactly
 * the same rules as one typed into the admin form. If the two disagreed, the
 * unique index would stop catching duplicates.
 *
 * De-duplication happens AFTER normalisation, which is the point: a file listing
 * "+1 555 010 0199" and "(555) 010-0199" contributes one number, not two.
 */
final class PhoneSpreadsheetReader extends ColumnSpreadsheetReader
{
    /**
     * Accepted header labels. Broader than the email reader's single "email"
     * because there is no settled convention for this column, and a file whose
     * header is not recognised falls back to scanning every cell — which would
     * pull in whatever else the sheet contains.
     *
     * @return list<string>
     */
    protected function headerNames(): array
    {
        return [
            'phone',
            'phones',
            'phone number',
            'phone_number',
            'phonenumber',
            'mobile',
            'mobile number',
            'msisdn',
            'number',
            'tel',
            'telephone',
        ];
    }

    protected function normalise(mixed $value): ?string
    {
        return PhoneNumber::normalise($value);
    }
}
