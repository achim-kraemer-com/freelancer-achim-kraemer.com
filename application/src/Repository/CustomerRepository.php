<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    use PaginationTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    /**
     * Paginated, searchable and filterable list of customers.
     *
     * @return array{items: array<int, mixed>, total: int, page: int, perPage: int, pages: int}
     */
    public function paginate(?string $q, ?string $active, int $page, int $perPage = 15): array
    {
        $qb = $this->createQueryBuilder('c');

        if ($q !== null && $q !== '') {
            $qb->andWhere('c.companyName LIKE :q OR c.firstname LIKE :q OR c.lastname LIKE :q OR c.email LIKE :q')
                ->setParameter('q', '%'.$q.'%');
        }

        if ($active === 'active') {
            $qb->andWhere('c.isActive = true');
        } elseif ($active === 'inactive') {
            $qb->andWhere('c.isActive = false');
        }

        $qb->orderBy('c.companyName', 'ASC')->addOrderBy('c.lastname', 'ASC');

        return $this->paginateQuery($qb, $page, $perPage);
    }

    /**
     * Active customers ordered by their most recent project activity.
     *
     * @return Customer[]
     */
    public function findRecentActive(int $limit = 5): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.projects', 'p')
            ->addSelect('MAX(p.createdAt) AS HIDDEN lastActivity')
            ->andWhere('c.isActive = true')
            ->groupBy('c.id')
            ->orderBy('lastActivity', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.isActive = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Number of projects per customer, in a single query (avoids N+1 counts).
     *
     * @param int[] $customerIds
     *
     * @return array<int, int> customerId => project count
     */
    public function projectCountsByCustomer(array $customerIds): array
    {
        if ([] === $customerIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.id AS id', 'COUNT(p.id) AS cnt')
            ->leftJoin('c.projects', 'p')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('ids', $customerIds)
            ->groupBy('c.id')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = (int) $row['cnt'];
        }

        return $map;
    }
}
