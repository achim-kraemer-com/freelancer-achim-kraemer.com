<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Fills the blank invoice PDF (Musterrechnung.pdf) with a text overlay
 * positioned in millimetres, using FPDI on top of TCPDF.
 */
class PdfService
{
    public function __construct(private readonly ContainerBagInterface $params)
    {
    }

    /**
     * @param array<int, list<array{x: float, y: float, text: string, B?: string, R?: string}>> $textOverlay
     *        keyed by page number; each entry positions a piece of text (mm).
     *
     * @return string the absolute path of the generated PDF
     */
    public function modifyPdf(array $textOverlay, string $outputFilename): string
    {
        $outputDir = $this->params->get('invoices_path');
        (new Filesystem())->mkdir($outputDir);

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $templateFile = $this->params->get('pdf_template_path').'Musterrechnung.pdf';
        $pageCount    = $pdf->setSourceFile($templateFile);

        for ($pageNo = 1; $pageNo <= $pageCount; ++$pageNo) {
            $templateId = $pdf->importPage($pageNo);
            $size       = $pdf->getTemplateSize($templateId);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            if (!isset($textOverlay[$pageNo])) {
                continue;
            }

            $pdf->SetTextColor(0, 0, 0);
            foreach ($textOverlay[$pageNo] as $textItem) {
                $fontStyle = \array_key_exists('B', $textItem) ? 'B' : '';
                $pdf->SetFont('Helvetica', $fontStyle, 11);

                $x = $textItem['x'];
                // Right-align: shift x left by the rendered string width so the text ends at x.
                if (\array_key_exists('R', $textItem)) {
                    $x -= $pdf->GetStringWidth($textItem['text']);
                }

                $pdf->SetXY($x, $textItem['y']);
                $pdf->Write(0, $textItem['text'], '', false, 'L');
            }
        }

        $outputPath = $outputDir.'Rechnung-'.$outputFilename.'.pdf';
        $pdf->Output($outputPath, 'F');

        return $outputPath;
    }
}
