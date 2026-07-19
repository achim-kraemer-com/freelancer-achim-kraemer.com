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
}
