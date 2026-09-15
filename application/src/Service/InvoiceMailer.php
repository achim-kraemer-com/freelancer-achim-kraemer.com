<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends an invoice to the customer: a short cover text, the signature the
 * invoice was given, and the PDF as attachment.
 */
final class InvoiceMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly ContainerBagInterface $params,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function send(Invoice $invoice, string $recipient, string $pdfPath, string $pdfName): void
    {
        $this->mailer->send($this->build($invoice, $recipient, $pdfPath, $pdfName));
    }

    /**
     * Builds the message without sending it – kept apart so that what would
     * go out can be inspected.
     */
    public function build(Invoice $invoice, string $recipient, string $pdfPath, string $pdfName): Email
    {
        $from      = (string) $this->params->get('mailer_from');
        $fromName  = (string) $this->params->get('mailer_from_name');
        $signature = $invoice->getSignature();

        $text = \sprintf(
            "Guten Tag,\n\nanbei erhalten Sie die Rechnung %s vom %s über %s €.\n\n"
            ."Vielen Dank für die Zusammenarbeit.\n\nMit freundlichen Grüßen",
            $invoice->getNumber(),
            $invoice->getInvoiceDate()?->format('d.m.Y'),
            \number_format((float) $invoice->getTotalAmount(), 2, ',', '.'),
        );

        // A signature brings the name along; without one the sender's name closes the mail.
        if ($signature === null) {
            $text .= "\n".$fromName;
        }

        $body = new MailBody($text, $signature);

        $email = (new Email())
            ->from(new Address($from, $fromName))
            ->replyTo($from)
            ->to($recipient)
            ->subject('Rechnung '.$invoice->getNumber())
            ->text($body->text())
            ->attachFromPath($pdfPath, $pdfName, 'application/pdf');

        // Only an HTML signature makes this an HTML mail; the text part stays
        // alongside it (multipart/alternative).
        $html = $body->html();

        if ($html !== null) {
            $email->html($this->embedImages($email, $html));
        }

        return $email;
    }

    /**
     * Images that sit in the HTML as data: URLs – that is how they come out of
     * the signature editor – go along as embedded attachments and are referred
     * to via cid: in the HTML. Some mail clients, Gmail first of all, do not
     * show data: images; embedded ones every client shows. Images with an
     * http(s) address stay as they are.
     */
    private function embedImages(Email $email, string $html): string
    {
        $count = 0;

        return (string) \preg_replace_callback(
            '#(<img\b[^>]*\bsrc\s*=\s*)(["\'])data:(image/[a-z0-9.+-]+);base64,([A-Za-z0-9+/=\s]+)\2#i',
            static function (array $m) use ($email, &$count): string {
                $binary = \base64_decode((string) \preg_replace('/\s+/', '', $m[4]), true);

                if ($binary === false || $binary === '') {
                    return $m[0];
                }

                $type = \strtolower($m[3]);
                $name = 'bild'.(++$count).'.'.self::extension($type);
                $email->embed($binary, $name, $type);

                return $m[1].$m[2].'cid:'.$name.$m[2];
            },
            $html,
        );
    }

    private static function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'    => 'jpg',
            'image/svg+xml' => 'svg',
            default         => (string) \preg_replace('/[^a-z0-9]/', '', \substr($mime, 6)),
        };
    }
}
