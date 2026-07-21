<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TimeEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TimeEntry>
 */
class TimeEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeEntry::class);
    }

    /**
     * Most recent logged time entries, with task/project/customer loaded.
     *
     * @return TimeEntry[]
     */
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.task', 't')->addSelect('t')
            ->join('t.project', 'p')->addSelect('p')
            ->join('p.customer', 'c')->addSelect('c')
            ->orderBy('e.workDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
