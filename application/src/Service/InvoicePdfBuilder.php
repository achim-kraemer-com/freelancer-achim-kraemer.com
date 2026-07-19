<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\Task;

/**
 * Builds the text overlay for an invoice and renders it onto the blank
 * invoice template via {@see PdfService}. Shared by invoice creation and
 * by the PDF (re)generation command.
 */
class InvoicePdfBuilder
{
    public function __construct(private readonly PdfService $pdf)
    {
    }

    /**
     * Renders the PDF for the given invoice and returns the stored file name.
     */
    public function generate(Invoice $invoice): string
    {
        $customer = $invoice->getCustomer();
        $number   = (string) $invoice->getNumber();

        // "Leistungszeitraum" = month of the earliest task; fall back to invoice date.
        $periodDate = $invoice->getInvoiceDate();
        foreach ($invoice->getTasks() as $task) {
            if (null !== $task->getCreatedAt() && $task->getCreatedAt() < $periodDate) {
                $periodDate = $task->getCreatedAt();
            }
        }

        $overlay = [
            1 => [
                ['x' => 25, 'y' => 45, 'text' => (string) $customer->getCompanyName()],
                ['x' => 25, 'y' => 50, 'text' => $customer->getFirstname().' '.$customer->getLastname()],
                ['x' => 25, 'y' => 55, 'text' => trim($customer->getStreet().' '.$customer->getHousenumber())],
                ['x' => 25, 'y' => 60, 'text' => trim($customer->getPlz().' '.$customer->getCity())],
                ['x' => 52, 'y' => 102.5, 'text' => $number],
                ['x' => 170, 'y' => 75, 'text' => $invoice->getInvoiceDate()->format('d.m.Y'), 'B' => 'B'],
            ],
        ];

        $total    = 0.0;
        $y        = 123;
        $position = 1;

        foreach ($invoice->getTasks() as $task) {
            $total += $task->getCost();

            $overlay[1][] = ['x' => 27, 'y' => $y, 'text' => (string) $position];
            $overlay[1][] = ['x' => 42, 'y' => $y, 'text' => (string) $task->getName()];

            if ($task->isHourly()) {
                $overlay[1][] = ['x' => 124, 'y' => $y, 'text' => str_replace('.', ',', $task->getHours()).' Std.'];
                $overlay[1][] = ['x' => 149, 'y' => $y, 'text' => Task::HOURLY_RATE.' €'];
            }
            $overlay[1][] = ['x' => 190, 'y' => $y, 'text' => $this->money($task->getCost()), 'R' => 'R'];

            $y += 5;

            if ($task->getDescription()) {
                foreach (explode("\n", $task->getDescription()) as $line) {
                    $line = trim($line);
                    if ('' === $line) {
                        continue;
                    }
                    $overlay[1][] = ['x' => 42, 'y' => $y, 'text' => $line];
                    $y += 5;
                }
            }

            $y += 3;
            ++$position;
        }

        $overlay[1][] = ['x' => 172, 'y' => 66, 'text' => $this->germanMonth($periodDate->format('M/Y'))];
        $overlay[1][] = ['x' => 190, 'y' => 194, 'text' => $this->money($total), 'B' => 'B', 'R' => 'R'];

        $this->pdf->modifyPdf($overlay, $number);

        return 'Rechnung-'.$number.'.pdf';
    }

    private function money(float $amount): string
    {
        return \number_format($amount, 2, ',', '.').' €';
    }

    private function germanMonth(string $monthDate): string
    {
        $months = [
            'Jan' => 'Jan.', 'Feb' => 'Feb.', 'Mar' => 'Mär.', 'Apr' => 'Apr.',
            'May' => 'Mai', 'Jun' => 'Jun.', 'Jul' => 'Jul.', 'Aug' => 'Aug.',
            'Sep' => 'Sep.', 'Oct' => 'Okt.', 'Nov' => 'Nov.', 'Dec' => 'Dez.',
        ];

        return \strtr($monthDate, $months);
    }
}
