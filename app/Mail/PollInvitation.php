<?php

namespace App\Mail;

use App\Models\Participant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PollInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Participant $participant)
    {
    }

    public function envelope(): Envelope
    {
        $organizer = $this->participant->poll->user;

        return new Envelope(
            // Replies go to the organizer, not the app's no-reply sender.
            replyTo: [new Address($organizer->email, $organizer->name)],
            subject: "{$organizer->name} wants to find a time: {$this->participant->poll->title}",
        );
    }

    public function content(): Content
    {
        $poll = $this->participant->poll;

        return new Content(
            markdown: 'mail.poll-invitation',
            with: [
                'poll' => $poll,
                'organizer' => $poll->user,
                'url' => $this->participant->personalUrl(),
                'optionCount' => $poll->slots()->count(),
            ],
        );
    }
}
