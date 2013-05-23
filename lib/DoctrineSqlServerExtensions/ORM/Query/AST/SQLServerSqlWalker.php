<?php

namespace DoctrineSqlServerExtensions\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker,
    Doctrine\ORM\Query\AST;

/**
 * Handles the complexities of SQL Server DISTINCT and ORDER BY queries in a
 * custom output walker.
 *
 * @author Craig Mason <craig.mason@stasismedia.com>
 */
class SQLServerSqlWalker extends SqlWalker
{
    /**
     * @var array
     */
    private $queryComponents;

    /**
     * @var int
     */
    private $firstResult;

    /**
     * @var int
     */
    private $maxResults;

    /**
     * Constructor. Stores various parameters that are otherwise unavailable
     * because Doctrine\ORM\Query\SqlWalker keeps everything private without
     * accessors.
     *
     * @param Doctrine\ORM\Query $query
     * @param Doctrine\ORM\Query\ParserResult $parserResult
     * @param array $queryComponents
     */
    public function __construct($query, $parserResult, array $queryComponents)
    {
        $this->queryComponents = $queryComponents;

        // Reset limit and offset
        $this->firstResult = $query->getFirstResult();
        $this->maxResults = $query->getMaxResults();

        parent::__construct($query, $parserResult, $queryComponents);
    }

    /**
     * SQL server lacks any form of LIMIT clause. It is necessary to use the
     * ROW_NUMBER() function. However, this requires the ORDER BY statement to
     * be moved outside of the inner query, and the columns must use their
     * aliases.
     *
     * The DISTINCT clause applies to every column in the SELECT, so it is
     * impossible to DISTINCT only on the normal identifier columns (particularly
     * useful during pagination queries).
     *
     * The solution is to use a number of nested queries and use a combination of
     * ROW_NUMBER() and PARTITION BY methods to simulate the required functionality.
     *
     * We no longer use $platform->doModifyLimitQuery()
     *
     * SELECT * FROM (
     *     SELECT ROW_NUMBER() OVER (order) AS rownumber,
     *     *
     *     FROM (
     *         SELECT ROW_NUMBER() OVER (PARTITION BY distinct_cols ..) AS distinct_row,
     *         rest_of_select
     *         FROM (
     *             original_query_without_orderby
     *         ) AS outer_table
     *         WHERE distinct_row = 1
     *     )
     * ) AS paged_result WHERE rownumber BETWEEN x AND y
     *
     * @param \Doctrine\ORM\Query\AST\SelectStatement $AST
     * @return string SQL
     */
    public function walkSelectStatement(AST\SelectStatement $AST)
    {
        $select = $this->walkSelectClause($AST->selectClause);
        $from   = $this->walkFromClause($AST->fromClause);
        $where  = $this->walkWhereClause($AST->whereClause);
	$orderBy = null;

        // Prepare the orderby as we may need to shift it about
        if($AST->orderByClause !== null)
        {
            $orderBy = $this->walkOrderByClause($AST->orderByClause);
        }

        // We'll do this in two batches
        if($AST->selectClause->isDistinct)
        {
            $identifiers = $this->getRootIdentifiers($AST);
            $tpl = 'ROW_NUMBER() OVER (PARTITION BY %s ORDER BY (SELECT 0)) as distinct_row, ';
            $partitionSql = sprintf($tpl, implode(', ', $identifiers));
            $select = preg_replace('/^SELECT DISTINCT/', 'SELECT ' . $partitionSql, $select);
        }

        // Main SQL. This no longer needs touching
        $sql = "$select\n$from\n$where";

        /*
         * Whilst we could append the standard ORDER BY clause when we are not
         * using pages results, it seems cleaner to use a common 'wrapper' for
         * our queries. Need to look into whether standard ORDER BY is quicker
         */
        if($orderBy !== null)
        {
            // We can't get the orderby aliases from a cache :(
            $orderParts = $this->getOrderParts($orderBy, $select);
            array_walk($orderParts, function($k, &$v){
                $v = 'outer_table.' . $v;
            });

            $over = 'ORDER BY ' . implode(', ', $orderParts);
        } else {
            $over = 'ORDER BY (SELECT 0)';
        }

        $sql = "SELECT ROW_NUMBER() OVER ($over) AS rownumber, * FROM ($sql) AS outer_table";

        if($AST->selectClause->isDistinct)
        {
            $sql .= " WHERE distinct_row = 1";
        }

        $limit = $this->getQuery()->getMaxResults();
        $offset = $this->getQuery()->getFirstResult();

        // If we have a LIMIT query, wrap the whole thing and use the earlier ROW_NUMBER()
        if ($limit || $offset)
        {
            // Row number starts at 1
            $start = ($offset == null ? 0 : $offset) + 1;
            $end = $start + $limit - 1;

            $sql = "SELECT * FROM ($sql) AS paged_result WHERE rownumber BETWEEN $start AND $end";
        }

        return $sql;
    }

    /**
     * @param \Doctrine\ORM\Query\AST\SelectStatement $AST
     * @return string
     * @throws \RuntimeException
     */
    protected function getRootIdentifiers(AST\SelectStatement $AST)
    {
        // Get the root entity and alias from the AST fromClause
        $from = $AST->fromClause->identificationVariableDeclarations;
        if (count($from) !== 1) {
            throw new \RuntimeException("Cannot count query which selects two FROM components, cannot make distinction");
        }

        $rootAlias      = $from[0]->rangeVariableDeclaration->aliasIdentificationVariable;
        $rootClass      = $this->queryComponents[$rootAlias]['metadata'];
        $rootIdentifiers = $rootClass->identifier;

        // For every identifier, find out the SQL alias by combing through the ResultSetMapping
        $sqlIdentifier = array();
        foreach ($rootIdentifiers as $property) {
            $sqlIdentifier[$property] = $this->getSQLTableAlias($rootClass->getTableName(), $rootAlias) . '.' . $rootClass->fieldMappings[$property]['columnName'];
        }

        return $sqlIdentifier;
    }

    /**
     * Fetches the ORDER BY components and translantes them into their earlier
     * aliased form using the $select part. This is required as there is no way
     * to find the aliased component from an earlier the walkSelectClause
     *
     * @param string $orderBy
     * @param string $select
     * @return array individual orderby parts including direction
     */
    protected function getOrderParts($orderBy, $select)
    {
        $orders = explode(', ', preg_replace('/\s?ORDER BY/', '', $orderBy));

        $aliasOrder = array();
        foreach($orders as $order)
        {
            $matches = array();
            preg_match('/([^\s]+)\s+(ASC|DESC)/', $order, $matches);

            $column = $matches[1];
            $direction = $matches[2];

            $regex = sprintf('/%s\s+AS\s([\w]+)/', $column);
            preg_match($regex, $select, $matches);
            $alias = $matches[1];

            $aliasOrder[] = $alias . ' ' . $direction;
        }

        return $aliasOrder;
    }
}
