<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Invoice;
use App\Entity\InvoiceDispatch;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    public function testPaidInvoiceIsNeverOverdue(): void
    {
        $invoice = (new Invoice())
            ->setStatus(Invoice::STATUS_PAID)
            ->setDueDate(new \DateTime('-10 days'));

        self::assertFalse($invoice->isOverdue());
    }

    public function testInvoiceWithoutDueDateIsNotOverdue(): void
    {
        $invoice = (new Invoice())->setStatus(Invoice::STATUS_SENT);

        self::assertFalse($invoice->isOverdue());
    }

    public function testUnpaidInvoiceWithFutureDueDateIsNotOverdue(): void
    {
        $invoice = (new Invoice())
            ->setStatus(Invoice::STATUS_SENT)
            ->setDueDate(new \DateTime('+5 days'));

        self::assertFalse($invoice->isOverdue());
        self::assertSame(5, $invoice->getDaysUntilDue());
    }

    public function testUnpaidInvoiceWithPastDueDateIsOverdue(): void
    {
        $invoice = (new Invoice())
            ->setStatus(Invoice::STATUS_SENT)
            ->setDueDate(new \DateTime('-3 days'));

        self::assertTrue($invoice->isOverdue());
        self::assertSame(-3, $invoice->getDaysUntilDue());
    }

    public function testLastSentAtTracksDispatches(): void
    {
        $invoice = new Invoice();
        self::assertNull($invoice->getLastSentAt());

        $sentAt = new \DateTimeImmutable('2026-01-15 10:00');
        $invoice->addDispatch((new InvoiceDispatch())->setRecipient('kunde@example.com')->setSentAt($sentAt));

        self::assertSame($sentAt, $invoice->getLastSentAt());
    }
}
