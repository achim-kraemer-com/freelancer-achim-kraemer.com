<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\MailSignature;
use App\Service\InvoiceMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Part\DataPart;

/**
 * The invoice mail: cover text, the invoice's signature, the PDF – and how
 * an HTML signature with an embedded image travels.
 */
final class InvoiceMailerTest extends TestCase
{
    /** A 1×1 PNG, as the editor embeds it after pasting an image. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $pdf;

    protected function setUp(): void
    {
        $this->pdf = \tempnam(\sys_get_temp_dir(), 'invoice').'.pdf';
        \file_put_contents($this->pdf, '%PDF-1.4 test');
    }

    protected function tearDown(): void
    {
        @\unlink($this->pdf);
    }

    public function testWithoutSignatureTheSenderNameClosesTheMail(): void
    {
        $email = $this->mailer()->build($this->invoice(), 'kunde@example.com', $this->pdf, 'Rechnung-26-1001-1.pdf');

        self::assertSame('Rechnung 26-1001-1', $email->getSubject());
        self::assertSame('kontakt@achim-kraemer.com', $email->getFrom()[0]->getAddress());
        self::assertSame('kunde@example.com', $email->getTo()[0]->getAddress());
        self::assertStringEndsWith("Mit freundlichen Grüßen\nAchim Krämer", (string) $email->getTextBody());
        self::assertStringContainsString('Rechnung 26-1001-1 vom 01.09.2026 über 1.234,50 €', (string) $email->getTextBody());
        self::assertNull($email->getHtmlBody());

        $attachments = $email->getAttachments();
        self::assertCount(1, $attachments);
        self::assertSame('Rechnung-26-1001-1.pdf', $attachments[0]->getFilename());
    }

    public function testTextSignatureStandsBehindTheDelimiter(): void
    {
        $invoice = $this->invoice()->setSignature((new MailSignature())->setText("Achim Krämer\nachim-kraemer.com"));

        $email = $this->mailer()->build($invoice, 'kunde@example.com', $this->pdf, 'Rechnung.pdf');

        self::assertStringEndsWith("Mit freundlichen Grüßen\n\n-- \nAchim Krämer\nachim-kraemer.com", (string) $email->getTextBody());
        self::assertNull($email->getHtmlBody(), 'A text signature keeps the mail plain text.');
    }

    public function testHtmlSignatureEmbedsItsImagesAsAttachments(): void
    {
        $signature = (new MailSignature())
            ->setFormat(MailSignature::FORMAT_HTML)
            ->setText('<div><b>Achim Krämer</b><br><img src="data:image/png;base64,'.self::PNG.'" alt=""></div>');

        $email = $this->mailer()->build($this->invoice()->setSignature($signature), 'kunde@example.com', $this->pdf, 'Rechnung.pdf');
        $html  = (string) $email->getHtmlBody();

        self::assertStringContainsString('src="cid:bild1.png"', $html);
        self::assertStringNotContainsString('data:image', $html);
        self::assertStringContainsString('Mit freundlichen Grüßen', $html);
        self::assertStringEndsWith("Mit freundlichen Grüßen\n\n-- \nAchim Krämer", (string) $email->getTextBody());

        // The PDF as regular attachment, the image inline.
        $parts = $email->getAttachments();
        self::assertCount(2, $parts);
        self::assertInstanceOf(DataPart::class, $parts[1]);
        self::assertSame('bild1.png', $parts[1]->getFilename());
        self::assertSame('inline', $parts[1]->getDisposition());
        self::assertSame(\base64_decode(self::PNG, true), $parts[1]->getBody());

        // The finished message: text and HTML part, the image under the same
        // Content-ID. Quoted-printable wraps long lines with "=\r\n" – join them first.
        $raw = \str_replace("=\r\n", '', $email->toString());
        self::assertStringContainsString('multipart/alternative', $raw);
        self::assertStringContainsString('multipart/related', $raw);
        self::assertSame(1, \preg_match('#src=3D"cid:([^"]+)"#', $raw, $cid));
        self::assertStringContainsString('Content-ID: <'.$cid[1].'>', $raw);
        self::assertStringContainsString("--=20\r\nAchim Kr=C3=A4mer", $raw, 'The text part carries the delimiter including its space.');
    }

    /** An image with an http address stays as it is – as Gmail does it. */
    public function testRemoteImagesStayLinked(): void
    {
        $signature = (new MailSignature())
            ->setFormat(MailSignature::FORMAT_HTML)
            ->setText('<img src="https://achim-kraemer.com/logo.png" alt="Logo">');

        $email = $this->mailer()->build($this->invoice()->setSignature($signature), 'kunde@example.com', $this->pdf, 'Rechnung.pdf');

        self::assertStringContainsString('src="https://achim-kraemer.com/logo.png"', (string) $email->getHtmlBody());
        self::assertCount(1, $email->getAttachments(), 'Only the PDF.');
    }

    private function mailer(): InvoiceMailer
    {
        $params = new ContainerBag(new Container(new ParameterBag([
            'mailer_from'      => 'kontakt@achim-kraemer.com',
            'mailer_from_name' => 'Achim Krämer',
        ])));

        return new InvoiceMailer($this->createStub(MailerInterface::class), $params);
    }

    private function invoice(): Invoice
    {
        return (new Invoice())
            ->setNumber('26-1001-1')
            ->setCustomer((new Customer())->setFirstname('Max')->setLastname('Muster')->setEmail('kunde@example.com'))
            ->setInvoiceDate(new \DateTime('2026-09-01'))
            ->setTotalAmount('1234.50');
    }
}
