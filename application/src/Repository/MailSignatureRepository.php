<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailSignature;
use App\Enum\InvoiceTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailSignature>
 */
class MailSignatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailSignature::class);
    }

    /** @return list<MailSignature> in the order they are shown in every selection */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function nextPosition(): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->getQuery()->getSingleScalarResult();

        return (int) $max + 1;
    }

    /** The signature new invoices with this letterhead are preset with, if one is marked as its default. */
    public function findDefaultFor(InvoiceTemplate $template): ?MailSignature
    {
        return $this->findOneBy(['defaultForTemplate' => $template], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Only one signature can be the default for a letterhead: drops the mark
     * from every other signature carrying it. Takes effect with the next flush.
     */
    public function clearDefaultFor(InvoiceTemplate $template, ?MailSignature $except = null): void
    {
        foreach ($this->findBy(['defaultForTemplate' => $template]) as $signature) {
            if ($signature !== $except) {
                $signature->setDefaultForTemplate(null);
            }
        }
    }
}
