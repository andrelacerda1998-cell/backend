<?php

namespace App\Mail\Vendor;

use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentSentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(private readonly Vendor $vendor, private readonly string $amount)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('notifications.mail.paymentSent.subject', locale: $this->vendor->user->language),
        );
    }

    public function content(): Content
    {
        return (new Content(
            view: 'emails.vendor.payment-sent',
        ))->with([
            'lang' => $this->vendor->user->language,
            'name' => $this->vendor->user->name,
            'iban' => $this->vendor->iban,
            'amount' => number_format($this->amount, 2, ',', '.'),
        ]);
    }

    public function attachments(): array
    {
        return [];
    }
}
