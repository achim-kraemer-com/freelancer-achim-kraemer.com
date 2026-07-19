<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\InvoiceRepository;
use App\Service\InvoicePdfBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-invoice-pdfs',
    description: 'Generate (or regenerate) the PDF for every invoice from the blank template',
)]
class GenerateInvoicePdfsCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoicePdfBuilder $pdfBuilder,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('missing-only', null, InputOption::VALUE_NONE, 'Only generate PDFs that do not exist yet');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $missingOnly = (bool) $input->getOption('missing-only');
        $count       = 0;

        foreach ($this->invoices->findBy([], ['id' => 'ASC']) as $invoice) {
            if ($missingOnly && null !== $invoice->getPdfFilename()) {
                continue;
            }

            $filename = $this->pdfBuilder->generate($invoice);
            $invoice->setPdfFilename($filename);
            $io->writeln(\sprintf('  <info>✓</info> %s (%s €)', $filename, $invoice->getTotalAmount()));
            ++$count;
        }

        $this->em->flush();
        $io->success(\sprintf('%d Rechnungs-PDF(s) erzeugt.', $count));

        return Command::SUCCESS;
    }
}
