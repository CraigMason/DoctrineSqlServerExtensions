<?php

namespace DoctrineSqlServerExtensions\ORM\Tools\SQLServer\Pagination;

use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\Paginator as BasePaginator;
use Doctrine\ORM\Tools\Pagination\WhereInWalker;

/**
 * Paginator
 *
 * Custom paginator for SQL server. Uses the Doctrine 2.2 ORM Paginator
 *
 * @author Craig Mason <craig.mason@stasismedia.com>
 */
class Paginator extends BasePaginator implements \Countable, \IteratorAggregate
{
    /**
     * Constructor.
     *
     * @param Query|QueryBuilder $query A Doctrine ORM query or query builder.
     * @param Boolean $fetchJoinCollection Whether the query joins a collection (true by default).
     */
    public function __construct($query, $fetchJoinCollection = true)
    {
        parent::__construct($query, $fetchJoinCollection);

        // We have to use the SQL Server query walker to get the correct wrapping.
        $this->getQuery()->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, 'DoctrineSqlServerExtensions\ORM\Query\AST\SQLServerSqlWalker');
    }

    public function getIterator()
    {
        $query = $this->getQuery();

        $offset = $query->getFirstResult();
        $length = $query->getMaxResults();

        if($this->getFetchJoinCollection())
        {
            /*
             * Use a DISTINT query to get all of the IDs. SQLServerSqlWalker
             * will handle the root ID collection
             */
            $subQuery = $this->cloneQuery($query);
            //$subQuery->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, 'StasisMedia\Database\Doctrine\ORM\Query\AST\SQLServer\SQLServerSqlWalker');
            $subQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, array('DoctrineSqlServerExtensions\ORM\Tools\SQLServer\Pagination\LimitSubqueryWalker'));
            $subQuery->setFirstResult($offset);
            $subQuery->setMaxResults($length);
            $ids = array_map('current', $subQuery->getScalarResult());

            // don't do this for an empty id array
            if (count($ids) == 0) {
                return new \ArrayIterator(array());
            }

            // Now use a wherein to grab the actual rows
            $whereInQuery = $this->cloneQuery($query);

            $namespace = WhereInWalker::PAGINATOR_ID_ALIAS;
            $whereInQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, array('Doctrine\ORM\Tools\Pagination\WhereInWalker'));
            $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_ID_COUNT, count($ids));
            $whereInQuery->setFirstResult(null)->setMaxResults(null);
            foreach ($ids as $i => $id) {
                $i++;
                $whereInQuery->setParameter("{$namespace}_{$i}", $id);
            }
            $hm = $query->getHydrationMode();
            $result = $whereInQuery->getResult($hm);

        } else {
            $result = $this->cloneQuery($query)
                ->setMaxResults($length)
                ->setFirstResult($offset)
                ->getResult($query->getHydrationMode());
        }

        return new \ArrayIterator($result);
    }

    /**
     * Clones a query.
     *
     * @param Query $query The query.
     *
     * @return Query The cloned query.
     */
    protected function cloneQuery(Query $query)
    {
        /* @var $cloneQuery Query */
        $cloneQuery = clone $query;

        $cloneQuery->setParameters(clone $query->getParameters());

        foreach ($query->getHints() as $name => $value) {
            $cloneQuery->setHint($name, $value);
        }

        return $cloneQuery;
    }
}