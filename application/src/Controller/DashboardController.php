<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\Task;
use App\Repository\CustomerRepository;
use App\Repository\InvoiceRepository;
use App\Repository\TaskRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    #[IsGranted('ROLE_USER')]
    public function index(
        CustomerRepository $customers,
        InvoiceRepository $invoices,
        TaskRepository $tasks,
    ): Response {
        // Non-admin users only get the plain welcome screen.
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->render('dashboard/index.html.twig');
        }

        $openInvoices = $invoices->findOpen();
        $openTasks    = $tasks->findOpen();

        $openInvoiceTotal = array_sum(array_map(
            static fn (Invoice $invoice): float => (float) $invoice->getTotalAmount(),
            $openInvoices,
        ));
        $openTaskTotal = array_sum(array_map(
            static fn (Task $task): float => $task->getCost(),
            $openTasks,
        ));

        $overdueInvoices = $invoices->findOverdue();
        $overdueTotal    = array_sum(array_map(
            static fn (Invoice $invoice): float => (float) $invoice->getTotalAmount(),
            $overdueInvoices,
        ));

        $year           = (int) date('Y');
        $month          = (int) date('n');
        $monthlyRevenue = $invoices->getMonthlyRevenue($year);
        $revenueYear    = array_sum($monthlyRevenue);
        $revenueMonth   = $monthlyRevenue[$month] ?? 0.0;

        $recentCustomers = $customers->findRecentActive(5);
        $projectCounts   = $customers->projectCountsByCustomer(
            array_map(static fn ($customer): int => $customer->getId(), $recentCustomers),
        );

        return $this->render('dashboard/index.html.twig', [
            'year'             => $year,
            'currentMonth'     => $month,
            'monthlyRevenue'   => $monthlyRevenue,
            'revenueYear'      => $revenueYear,
            'revenueMonth'     => $revenueMonth,
            'recentCustomers'  => $recentCustomers,
            'projectCounts'    => $projectCounts,
            'activeCustomers'  => $customers->countActive(),
            'openInvoices'     => $openInvoices,
            'openInvoiceTotal' => $openInvoiceTotal,
            'overdueInvoices'  => $overdueInvoices,
            'overdueTotal'     => $overdueTotal,
            'openTasks'        => $openTasks,
            'openTaskTotal'    => $openTaskTotal,
        ]);
    }
}
