<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\InvoiceTemplate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

/**
 * Builds the blank Mirwena invoice template (config/pdfs/Musterrechnung-Mirwena.pdf):
 * the mirwena.de logo plus the fixed letterhead text. All positions mirror
 * Musterrechnung.pdf (Google-Docs export, Arial 11 pt, 1-inch margins) so the
 * text overlay in {@see \App\Service\InvoicePdfBuilder} fits both templates.
 * Run it again after changing any of the fixed text below.
 */
#[AsCommand(
    name: 'app:build-mirwena-template',
    description: 'Build the blank Mirwena invoice PDF (logo + letterhead) into config/pdfs/',
)]
class BuildMirwenaTemplateCommand extends Command
{
    /** Left text margin and right edge of the contact block / amount column (mm). */
    private const float LEFT  = 25.4;
    private const float RIGHT = 190.5;

    public function __construct(private readonly ContainerBagInterface $params)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $dir = $this->params->get('pdf_template_path');

        $pdf = new \TCPDF('P', 'mm', 'A4');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setAutoPageBreak(false);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->AddPage();

        // Logo lockup (signet 12 mm high, width follows the image ratio), top left above the sender line.
        $pdf->Image($dir.'mirwena-logo.png', self::LEFT, 13.5, 0, 12, 'PNG');

        // Sender line above the address window; the overlay puts the recipient at y = 45–60.
        $this->text($pdf, self::LEFT, 34.17, 'Achim Krämer | Böckemeyerskamp 20 | 49479 Ibbenbüren');

        // Contact block, right-aligned. The overlay writes the date at y = 75 and the period at x = 172.
        $this->textRight($pdf, self::RIGHT, 49.57, 'Telefon: 01756 821391');
        $this->textRight($pdf, self::RIGHT, 54.70, 'E-Mail: kontakt@mirwena.de');
        $this->textRight($pdf, self::RIGHT, 59.83, 'Internet: mirwena.de');
        $this->textRight($pdf, self::RIGHT, 64.96, 'Steuernummer: 327/2047/2306');
        $this->textRight($pdf, 171.4, 70.09, 'Leistungszeitraum:');

        // Invoice header and item table head; the overlay writes the number at x = 52 and items from y = 123.
        $this->text($pdf, 26.48, 95.75, 'Für die Erledigung der von Ihnen beauftragten Tätigkeiten berechne ich Ihnen wie folgt:');
        $this->rule($pdf, 100.36);
        $this->text($pdf, 26.48, 106.01, 'Rechnung Nr.', 'B');
        $this->rule($pdf, 110.59);
        $this->text($pdf, 26.48, 116.28, 'Pos.');
        $this->text($pdf, 43.93, 116.28, 'Bezeichnung');
        $this->text($pdf, 125.61, 116.28, 'Menge');
        $this->text($pdf, 143.93, 116.28, 'Einzelpreis/€');
        $this->text($pdf, 172.60, 116.28, 'Gesamt/€');
        $this->rule($pdf, 120.83);

        // Total line (the overlay writes the amount at y = 194) and closing text.
        $this->text($pdf, self::LEFT, 198.38, 'Rechnungsbetrag', 'B');
        $this->text($pdf, self::LEFT, 208.65, 'Vielen Dank für Ihren Auftrag!');
        $this->text($pdf, self::LEFT, 213.78, 'Ich bitte um Überweisung des Rechnungsbetrages innerhalb von 14 Tagen an');
        $this->text($pdf, self::LEFT, 218.91, 'IBAN: DE67 1203 0000 1201 8122 43');
        $this->text($pdf, self::LEFT, 224.04, 'BIC: BYLADEM1001');
        $this->text($pdf, self::LEFT, 234.30, 'Mit freundlichen Grüßen');
        $this->text($pdf, self::LEFT, 239.44, 'Achim Krämer');
        $this->text($pdf, self::LEFT, 249.70, 'Hinweis: Als Kleinunternehmer im Sinne von § 19 Abs. 1 UStG wird Umsatzsteuer nicht berechnet', '', 10);

        $path = $dir.InvoiceTemplate::Mirwena->pdfFile();
        $pdf->Output($path, 'F');

        $io->success('Vorlage geschrieben: '.$path);

        return Command::SUCCESS;
    }

    /** Places $text with its baseline exactly at $baseline (mm), left edge at $x. */
    private function text(\TCPDF $pdf, float $x, float $baseline, string $text, string $style = '', float $size = 11): void
    {
        $pdf->SetFont('Helvetica', $style, $size);
        // calign 'L' + valign 'T' anchor the cell at the font baseline instead of its top.
        $pdf->Text($x, $baseline, $text, 0, false, true, 0, 0, '', false, '', 0, false, 'L', 'T');
    }

    private function textRight(\TCPDF $pdf, float $rightEdge, float $baseline, string $text): void
    {
        $pdf->SetFont('Helvetica', '', 11);
        $this->text($pdf, $rightEdge - $pdf->GetStringWidth($text), $baseline, $text);
    }

    /** Grey 1 pt separator across the text column, as in the original template. */
    private function rule(\TCPDF $pdf, float $y): void
    {
        $pdf->SetDrawColor(136, 136, 136);
        $pdf->SetLineWidth(0.353);
        $pdf->Line(26.46, $y, 189.44, $y);
    }
}
