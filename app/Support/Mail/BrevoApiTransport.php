<?php

namespace App\Support\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Brevo REST API Transport (HTTPS / Port 443).
 *
 * يوفر إرسال البريد عبر واجهة برمجة تطبيقات Brevo (HTTPS) لتجاوز قيود
 * حظر منافذ الـ SMTP (587/465/25) في الاستضافات السحابية مثل Render.
 *
 * يلتزم بمبادئ SOLID (LSP / SRP / OCP) كامتداد قياسي لـ Symfony Transport.
 */
class BrevoApiTransport extends AbstractTransport
{
    public function __construct(private readonly string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $sender = $email->getFrom()[0] ?? null;
        $fromEmail = $sender ? $sender->getAddress() : config('mail.from.address');
        $fromName = $sender ? ($sender->getName() ?: config('mail.from.name')) : config('mail.from.name');

        $recipients = [];
        foreach ($email->getTo() as $recipient) {
            $recipients[] = [
                'email' => $recipient->getAddress(),
                'name' => $recipient->getName() ?: null,
            ];
        }

        $htmlContent = $email->getHtmlBody();
        if (is_resource($htmlContent)) {
            $htmlContent = stream_get_contents($htmlContent);
        }

        $textContent = $email->getTextBody();
        if (is_resource($textContent)) {
            $textContent = stream_get_contents($textContent);
        }

        $payload = [
            'sender' => [
                'name' => $fromName,
                'email' => $fromEmail,
            ],
            'to' => $recipients,
            'subject' => $email->getSubject(),
            'htmlContent' => (string) ($htmlContent ?: $textContent),
        ];

        if ($textContent) {
            $payload['textContent'] = (string) $textContent;
        }

        // دعم المرفقات الفعلية فقط (استثناء المرفقات المضمنة لكي لا تظهر كملف تنزيل للمستخدم)
        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $disposition = $attachment->getPreparedHeaders()->get('content-disposition');
            if ($disposition && str_contains(strtolower($disposition->getBodyAsString()), 'inline')) {
                continue;
            }

            $attachments[] = [
                'name' => $attachment->getFilename() ?: 'attachment',
                'content' => base64_encode($attachment->getBody()),
            ];
        }

        if (! empty($attachments)) {
            $payload['attachment'] = $attachments;
        }

        $response = Http::withHeaders([
            'api-key' => $this->apiKey,
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])->timeout(15)->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Brevo API Mail Delivery Failed: '.$response->body());
        }
    }

    public function __toString(): string
    {
        return 'brevo-api';
    }
}
