<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    use PaginationTrait;

    /** Allowed values for the status filter in the invoice list. */
    public const array STATUS_FILTERS = [
        Invoice::STATUS_CREATED,
        Invoice::STATUS_SENT,
        Invoice::STATUS_PAID,
        'ueberfaellig',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * Paginated, searchable and status-filterable list of invoices.
     *
     * @return array{items: array<int, mixed>, total: int, page: int, perPage: int, pages: int}
     */
    public function paginate(?string $q, ?string $status, int $page, int $perPage = 15): array
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.customer', 'c')->addSelect('c');

        if ($q !== null && $q !== '') {
            $qb->andWhere('i.number LIKE :q OR c.companyName LIKE :q OR c.firstname LIKE :q OR c.lastname LIKE :q')
                ->setParameter('q', '%'.$q.'%');
        }

        if (\in_array($status, self::STATUS_FILTERS, true)) {
            if ($status === 'ueberfaellig') {
                $qb->andWhere('i.status != :paid')
                    ->andWhere('i.dueDate IS NOT NULL')
                    ->andWhere('i.dueDate < :today')
                    ->setParameter('paid', Invoice::STATUS_PAID)
                    ->setParameter('today', new \DateTime('today'));
            } else {
                $qb->andWhere('i.status = :status')->setParameter('status', $status);
            }
        }

        $qb->orderBy('i.invoiceDate', 'DESC')->addOrderBy('i.id', 'DESC');

        return $this->paginateQuery($qb, $page, $perPage);
    }

    /**
     * Invoices that are not yet paid (status "erstellt" or "verschickt"),
     * most recent first.
     *
     * @return Invoice[]
     */
    public function findOpen(): array
    {
        return $this->createQueryBuilder('i')
            ->join('i.customer', 'c')->addSelect('c')
            ->andWhere('i.status != :paid')
            ->setParameter('paid', Invoice::STATUS_PAID)
            ->orderBy('i.invoiceDate', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Paid revenue per month for a given year (grouped in PHP to avoid
     * database-specific DQL date functions).
     *
     * @return array<int, float> month number (1-12) => summed amount (0.0 filled)
     */
    public function getMonthlyRevenue(int $year): array
    {
        $start = new \DateTime($year.'-01-01');
        $next  = new \DateTime(($year + 1).'-01-01');

        $rows = $this->createQueryBuilder('i')
            ->select('i.paidAt AS paidAt', 'i.totalAmount AS total')
            ->andWhere('i.status = :paid')
            ->andWhere('i.paidAt >= :start')
            ->andWhere('i.paidAt < :next')
            ->setParameter('paid', Invoice::STATUS_PAID)
            ->setParameter('start', $start)
            ->setParameter('next', $next)
            ->getQuery()
            ->getResult();

        $months = array_fill(1, 12, 0.0);
        foreach ($rows as $row) {
            $month = (int) $row['paidAt']->format('n');
            $months[$month] += (float) $row['total'];
        }

        return $months;
    }

    /**
     * Unpaid invoices whose due date has passed, most overdue first.
     *
     * @return Invoice[]
     */
    public function findOverdue(): array
    {
        return $this->createQueryBuilder('i')
            ->join('i.customer', 'c')->addSelect('c')
            ->andWhere('i.status != :paid')
            ->andWhere('i.dueDate IS NOT NULL')
            ->andWhere('i.dueDate < :today')
            ->setParameter('paid', Invoice::STATUS_PAID)
            ->setParameter('today', new \DateTime('today'))
            ->orderBy('i.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
