<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A stored credential that can run a campaign against the Mailgun receiver list.
 *
 * Implemented by {@see \App\Models\MailgunKey} (Mailgun API) and
 * {@see \App\Models\SmtpCredential} (an own SMTP server). The two differ ONLY in
 * how they authenticate — the audience, the selection rule, the per-day claim,
 * the message and the history are one shared implementation, reached through
 * this interface.
 *
 * That is the whole point of the interface: {@see \App\Services\ReceiverCampaignSender}
 * and {@see \App\Services\MailgunReceiverSelector} must never learn which channel
 * they are running, or the two would drift into selecting and sending different
 * things — the failure the selector's own docblock warns about.
 *
 * Implementations are Eloquent models, so `getKey()` is already available.
 */
interface ReceiverCampaignCredential
{
    /** Receivers this credential takes per run. */
    public function campaignBatchSize(): int;

    /** One of {@see \App\Models\MailgunReceiver::ORDERS}. */
    public function campaignSelectionOrder(): string;

    /** Days a receiver rests after a send; null or < 1 disables the filter. */
    public function campaignCooldownDays(): ?int;

    public function campaignSubject(): string;

    public function campaignHtml(): string;

    /** The authored fields behind the HTML — see {@see \App\Support\Mail\MailgunReceiverTemplate}. */
    public function campaignTemplate(): ?array;

    public function campaignFromAddress(): ?string;

    public function campaignFromName(): ?string;

    /** Admin-facing label, used in logs and in the run response. */
    public function campaignLabel(): string;

    public function isActive(): bool;

    /** True when the credential holds everything it needs to authenticate a send. */
    public function canAuthenticate(): bool;

    /** 'mailgun' | 'smtp' — recorded on the daily claim for diagnosis. */
    public function campaignChannel(): string;

    /** History table for this channel: `mailgun_receiver_sends` or `smtp_receiver_sends`. */
    public function campaignHistoryTable(): string;

    /**
     * The credential foreign key in that table — `mailgun_key_id` or
     * `smtp_credential_id`.
     *
     * Each channel keeps its own history table, so the writer has to know where
     * to write and which key to fill. Keeping both here, rather than branching on
     * the class inside the sender, is what lets a third channel be added without
     * touching the send loop.
     */
    public function campaignHistoryColumn(): string;
}
