<?php

declare(strict_types=1);

namespace App\Models\Contracts;

use App\Models\MailgunReceiver;
use App\Models\SendgridReceiver;
use App\Models\WarmupEmail;

/**
 * A row on a GLOBAL mailing list that carries its own opt-out credential:
 * {@see \App\Models\MailgunReceiver}, {@see \App\Models\SendgridReceiver},
 * {@see \App\Models\WarmupEmail}.
 *
 * The contract exists so two things can be written once instead of three
 * times, each time correctly: the backfill seeder and the schedule's
 * per-recipient link building. Anything added to this list later is reached by
 * both for free.
 *
 * What it deliberately does NOT carry is a verify link. These addresses are
 * entered by an operator who already has them, so there is no opt-in claim to
 * confirm — only the right to opt out. Double opt-in stays a subscriber
 * concept; see {@see \App\Http\Controllers\Api\Public\VerifyController}.
 *
 * {@see \App\Models\Newsletter} deliberately does NOT implement it. A
 * subscriber carries FOUR tokens, one per template, so an opt-out can be
 * attributed to the stream it came from, and its links are built per site from
 * that site's editable template. Flattening that into "the token" to satisfy
 * one interface would lose the attribution. The two shapes meet instead at
 * {@see \App\Support\Mail\ScheduleRecipient}, which is what the mail services
 * actually take.
 */
interface TokenAddressable
{
    /**
     * Every global list, for the callers that must cover all of them — today
     * {@see \Database\Seeders\ReceiverUnsubscribeTokenSeeder}. Adding a list
     * here reaches them.
     *
     * Enumerated rather than discovered: the caller writes to production data
     * and must not depend on what happens to be autoloaded at the time.
     *
     * @var list<class-string<self>>
     */
    public const array LISTS = [MailgunReceiver::class, SendgridReceiver::class, WarmupEmail::class];

    /** The bearer credential in this row's opt-out links. Never in a response. */
    public function unsubscribeToken(): string;

    /** The same, minting and saving one first if this row has none yet. */
    public function ensureUnsubscribeToken(): string;

    /** Absolute, token-addressed opt-out page for the list this row is on. */
    public function unsubscribeUrl(): string;

    /**
     * The RFC 8058 one-click POST target.
     *
     * Same token, same route: every list's unsubscribe path answers both POST
     * (acts) and GET (renders a confirm page), so one URL serves both headers
     * and the in-body link.
     */
    public function oneClickUrl(): string;

    /** The greeting name, when the list stores one. */
    public function recipientName(): ?string;

    /** Public route prefix of this list's unsubscribe links, under `/api/v1/`. */
    public static function unsubscribePath(): string;
}
