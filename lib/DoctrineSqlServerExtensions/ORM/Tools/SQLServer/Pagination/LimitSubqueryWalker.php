<?php

namespace DoctrineSqlServerExtensions\ORM\Tools\SQLServer\Pagination;

use Doctrine\ORM\Query\TreeWalkerAdapter;
use Doctrine\ORM\Query\AST\SelectStatement;

class LimitSubqueryWalker extends TreeWalkerAdapter
{
    public function walkSelectStatement(SelectStatement $AST)
    {
        $AST->selectClause->isDistinct = true;
    }
}