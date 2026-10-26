<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Project;
use PHPUnit\Framework\TestCase;

final class ProjectTest extends TestCase
{
    public function testInvoiceNumberCarriesYearProjectAndRunningNumber(): void
    {
        // A project that was never stored has no id, so the middle part is
        // the bare offset – the shape is what matters here.
        self::assertSame('26-1000-1', (new Project())->nextInvoiceNumber(new \DateTime('2026-05-18')));
    }

    public function testEachCallHandsOutTheNextRunningNumber(): void
    {
        $project = new Project();
        $date    = new \DateTime('2026-05-18');

        self::assertSame('26-1000-1', $project->nextInvoiceNumber($date));
        self::assertSame('26-1000-2', $project->nextInvoiceNumber($date));
        self::assertSame('26-1000-3', $project->nextInvoiceNumber($date));
    }

    public function testTheCounterNeverGoesBack(): void
    {
        $project = new Project();
        $date    = new \DateTime('2026-05-18');

        $project->nextInvoiceNumber($date);
        $project->nextInvoiceNumber($date);

        // Whatever happens to the invoices themselves, the project has handed
        // out two numbers and the third one is the next, never a repeat.
        self::assertSame(2, $project->getInvoiceCounter());
        self::assertSame('26-1000-3', $project->nextInvoiceNumber($date));
    }

    public function testTheYearComesFromTheInvoiceDate(): void
    {
        $project = new Project();

        self::assertSame('24-1000-1', $project->nextInvoiceNumber(new \DateTime('2024-12-03')));
        self::assertSame('25-1000-2', $project->nextInvoiceNumber(new \DateTime('2025-01-07')));
    }

    public function testAFreshProjectHasHandedOutNothing(): void
    {
        self::assertSame(0, (new Project())->getInvoiceCounter());
    }
}
