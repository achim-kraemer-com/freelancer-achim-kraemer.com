<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

trait PaginationTrait
{
    /**
     * Executes a query builder as a page and returns the items plus paging metadata.
     *
     * @return array{items: array<int, mixed>, total: int, page: int, perPage: int, pages: int}
     */
    private function paginateQuery(QueryBuilder $qb, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);

        $paginator = new Paginator($qb, false);
        $total     = \count($paginator);

        return [
            'items'   => iterator_to_array($paginator),
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
