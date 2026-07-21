<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * @param int[] $ids
     *
     * @return Task[]
     */
    public function findByIdsForProject(array $ids, int $projectId): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->andWhere('t.id IN (:ids)')
            ->andWhere('t.project = :project')
            ->andWhere('t.invoiced = false')
            ->setParameter('ids', $ids)
            ->setParameter('project', $projectId)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Open (not yet invoiced) tasks, most recent first, with project and
     * customer eagerly loaded for dashboard listing.
     *
     * @return Task[]
     */
    public function findOpen(): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.project', 'p')->addSelect('p')
            ->join('p.customer', 'c')->addSelect('c')
            ->andWhere('t.invoiced = false')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Open (not yet invoiced) tasks for the time-tracking dropdown,
     * ordered so they can be grouped by customer and project.
     *
     * @return Task[]
     */
    public function findOpenForTracking(): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.project', 'p')->addSelect('p')
            ->join('p.customer', 'c')->addSelect('c')
            ->andWhere('t.invoiced = false')
            ->orderBy('c.companyName', 'ASC')
            ->addOrderBy('c.lastname', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->addOrderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
