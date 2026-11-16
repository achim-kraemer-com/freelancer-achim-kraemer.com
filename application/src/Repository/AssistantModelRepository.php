<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssistantModel;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssistantModel>
 */
class AssistantModelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantModel::class);
    }

    /** @return list<AssistantModel> */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.owner = :owner')->setParameter('owner', $user)
            ->orderBy('m.position', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Das Modell, mit dem gearbeitet wird, wenn keines ausgewählt ist: das als
     * Vorgabe markierte, sonst das erste.
     */
    public function findDefaultFor(User $user): ?AssistantModel
    {
        $models = $this->findForUser($user);

        foreach ($models as $model) {
            if ($model->isDefault()) {
                return $model;
            }
        }

        return $models[0] ?? null;
    }

    public function nextPosition(User $user): int
    {
        $max = $this->createQueryBuilder('m')
            ->select('MAX(m.position)')
            ->andWhere('m.owner = :owner')->setParameter('owner', $user)
            ->getQuery()->getSingleScalarResult();

        return (int) $max + 1;
    }

    /** Setzt die Vorgabe auf genau ein Modell. */
    public function makeDefault(AssistantModel $model): void
    {
        $em = $this->getEntityManager();

        foreach ($this->findForUser($model->getOwner()) as $other) {
            $other->setIsDefault($other === $model);
        }

        $em->flush();
    }
}
