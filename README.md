Doctrine SQL Server Extensions
=============================

SQL Server specific extensions for Doctrine to make life easier. 

The purpose of this repository is to act as a testbed for Doctrine libraries 
when using SQL Server.

Only SQL Server 2005 will be supported, as many of the features require
functions that were not available in 2000, and I wish to avoid the use of heavy
prepared statements.

All of the classes in this repo have only been tested in basic scenarios.

## SQL Server Sql Output Walker

This aims to be a drop-in replacement for the standard SqlWalker provided in 
the ORM. This avoids using `doModifyLimitQuery()` in `SQLServerPlatform.php`
which is incredibly complex to handle with regex alone.

There is currently no way to set a default output walker, so the walker
must be specified via a hint

```php
<?php

$query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, 'DoctrineSqlServerExtensions\ORM\Query\AST\SQLServerSqlWalker');
```

## Paginator

The Paginator aims to be a 1:1 replacement for the Paginator included in Doctrine 2.2.x

```php
<?php
use DoctrineSqlServerExtensions\ORM\Tools\Pagination\Paginator;

$dql = "SELECT p, c FROM BlogPost p JOIN p.comments c";
$query = $entityManager->createQuery($dql)
                       ->setFirstResult(0)
                       ->setMaxResults(100);

$paginator = new Paginator($query, $fetchJoinCollection = true);

$c = count($paginator);
foreach ($paginator as $post) {
    echo $post->getHeadline() . "\n";
}
```
