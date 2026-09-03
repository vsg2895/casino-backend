<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An address that must never be mailed again through the Mailgun pipeline.
 *
 * Absolute and global: no credential may send to a suppressed address. This is
 * what keeps a hard bounce or a complaint from being re-sent on the next run and
 * dragging the sending domain's reputation down with it.
 *
 * Deliberately separate from {@see Unsubscribe}, which is per-site and
 * per-stream and belongs to the newsletter flow.
 */
class MailgunSuppression extends Model
{
    public const string REASON_UNSUBSCRIBE = 'unsubscribe';
    public const string REASON_BOUNCE = 'bounce';
    public const string REASON_COMPLAINT = 'complaint';
    public const string REASON_MANUAL = 'manual';

    /** @var list<string> */
    public const array REASONS = [
        self::REASON_UNSUBSCRIBE,
        self::REASON_BOUNCE,
        self::REASON_COMPLAINT,
        self::REASON_MANUAL,
    ];

    protected $fillable = ['email', 'reason', 'detail'];

    /** Same normalisation as MailgunReceiver, or a suppression could be bypassed by casing. */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): string => Str::lower(trim((string) $value)),
        );
    }

    /**
     * Suppress an address once, idempotently.
     *
     * updateOrCreate rather than create: a bounce webhook may fire twice for the
     * same message, and a duplicate-key error must not fail the webhook and make
     * Mailgun retry it forever.
     */
    public static function suppress(string $email, string $reason, ?string $detail = null): self
    {
        return self::updateOrCreate(
            ['email' => Str::lower(trim($email))],
            ['reason' => $reason, 'detail' => $detail],
        );
    }
}
