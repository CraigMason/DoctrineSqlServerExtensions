<?php

namespace DoctrineSqlServerExtensions\ORM\Tools\SQLServer\Pagination;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
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
            //$ids = array_map('current', $subQuery->getScalarResult());
            
            $keyIdentifier = false;
            $ids = array();
            foreach ($subQuery->getScalarResult() as $scalarResult) {
                if (!$keyIdentifier) {
                    if (array_key_exists('q_id', $scalarResult)) {
                        $keyIdentifier = 'q_id';
                    } else {
                        foreach (array_keys($scalarResult) as $key) {
                            if ('_id' == substr($key, 1)) {
                                $keyIdentifier = $key;
                                break;
                            }
                        }
                    }
                }
                if ($keyIdentifier) {
                    $ids[] = $scalarResult[$keyIdentifier];
                } else {
                    // No key matching found.
                    // What should we do???
                    // To see that situation, we can look for a IN(-1)
                    // request... 
                    $ids[] = -1;
                }
            }
            $ids = array_unique($ids);

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
            //foreach ($ids as $i => $id) {
            //    $i++;
            //    $whereInQuery->setParameter("{$namespace}_{$i}", $id);
            //}
            $whereInQuery->setParameter("{$namespace}", $ids);
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
