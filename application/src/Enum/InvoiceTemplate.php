<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Letterhead an invoice is issued with. Each case maps to a blank PDF in
 * config/pdfs/ that {@see \App\Service\PdfService} writes the invoice text onto.
 */
enum InvoiceTemplate: string
{
    case AchimKraemer = 'achim-kraemer';
    case Mirwena      = 'mirwena';

    public function label(): string
    {
        return match ($this) {
            self::AchimKraemer => 'Achim Krämer',
            self::Mirwena      => 'Mirwena',
        };
    }

    /** File name of the blank template inside the pdf_template_path directory. */
    public function pdfFile(): string
    {
        return match ($this) {
            self::AchimKraemer => 'Musterrechnung.pdf',
            self::Mirwena      => 'Musterrechnung-Mirwena.pdf',
        };
    }
}
