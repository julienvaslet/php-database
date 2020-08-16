<?php
// Copyright (c) Julien Vaslet

namespace database;

require_once(__DIR__."/Column.class.php");
require_once(__DIR__."/Database.class.php");
require_once(__DIR__."/OrderBy.class.php");
require_once(__DIR__."/filters/Filter.class.php");
require_once(__DIR__."/filters/AndFilter.class.php");
require_once(__DIR__."/filters/Equal.class.php");

use database\filters\Filter;
use database\filters\AndFilter;
use database\filters\Equal;


class Table
{
    protected bool $__newRow;
    protected bool $__lazy;

    /**
     * Create a new instance for a new object that doesn't exist in the database.
     * Parameters of this constructor are the class attributes in their order of definition.
     * Auto-incremented values are ignored, because yes they will be automatically incremented :)
     */
    public function __construct(...$values)
    {
        $this->__newRow = true;
        $this->__lazy = false;
        $argumentsCount = count($values);

        foreach (static::getColumns() as $column)
        {
            if ($column->isAutoIncremented())
            {
                $this->{$column->getName()} = 0;
            }
            else if (count($values))
            {
                $this->{$column->getName()} = $column->parseValue(array_shift($values));
            }
            else
            {
                // TODO: Get default values if defined or null if allowed
                throw new \Exception("Not enough arguments: ${argumentsCount} specified, ".count(static::getColumns())." expected.");
            }
        }

        if (count($values))
        {
            throw new \Exception("Too many arguments: ${argumentsCount} specified, ".($argumentsCount - count($values))." expected.");
        }
    }

    public function getPrimaryKey() : array
    {
        $primaryKey = array();

        foreach (static::getPrimaryKeyColumns() as $column)
        {
            $primaryKey[] = $this->{$column->getName()};
        }

        return $primaryKey;
    }

    public function isLazy() : bool
    {
        return $this->__lazy;
    }

    public function completeLoading()
    {
        $object = static::get(...$this->getPrimaryKey());

        foreach (static::getColumns() as $column)
        {
            $this->{$column->getName()} = $object->{$column->getName()};
        }
    }

    public function save()
    {
        if ($this->__lazy === true)
        {
            throw new Exception("Can't save lazy-loaded objects, please complete its load first with completeLoading() method.");
        }

        if ($this->__newRow === true)
        {
            $data = array();
            $autoIncrementColumn = null;

            foreach (static::getColumns() as $column)
            {
                // Auto-incremented field should be handled on the database side.
                if ($column->isAutoincremented())
                {
                    $autoIncrementColumn = $column;
                    continue;
                }

                $data[$column->getName()] = $this->{$column->getName()};
            }

            Database::get()->query(static::getInsertIntoQuery($data));

            if (!is_null($autoIncrementColumn))
            {
                $this->{$autoIncrementColumn->getName()} = Database::get()->getLastInsertedId();
            }

            $this->__newRow = false;
        }
        else
        {
            $data = array();
            $filters = new AndFilter();

            foreach (static::getColumns() as $column)
            {
                if ($column->isPrimaryKey())
                {
                    $filters->append(new Equal($column, $this->{$column->getName()}));
                }
                else
                {
                    $data[$column->getName()] = $this->{$column->getName()};
                }
            }

            // When there is no primary key, use the data as filter.
            if (count($filters) == 0)
            {
                // TODO: optimize to have only one call to getColumns()
                foreach (static::getColumns() as $column)
                {
                    $filters->append(new Equal($column, $this->{$column->getName()}));
                }
            }

            Database::get()->query(static::getUpdateQuery($data, $filters));
        }
    }

    public function delete()
    {
        if ($this->__newRow === true)
        {
            throw new Exception("Can't delete a row that hasn't been inserted yet.");
        }

        $filters = new AndFilter();

        foreach (static::getPrimaryKeyColumns() as $column)
        {
            $filters->append(new Equal($column, $this->{$column->getName()}));
        }

        // When there is no primary key, use the data as filter.
        if (count($filters) == 0)
        {
            foreach (static::getColumns() as $column)
            {
                $filters->append(new Equal($column, $this->{$column->getName()}));
            }
        }

        Database::get()->query(static::getDeleteQuery($filters));
    }

    public static function column($name) : Column
    {
        $class = new \ReflectionClass(static::class);

        if (!$class->hasProperty($name))
        {
            throw new \Exception("Invalid column name \"${name}\".");
        }

        $property = $class->getProperty($name);
        return new Column($property);
    }

    public static function getColumns() : array
    {
        $columns = array();
        $class = new \ReflectionClass(static::class);

        foreach ($class->getProperties() as $property)
        {
            // Ignore attributes starting with "__".
            if (substr($property->getName(), 0, 2) != "__")
            {
                $columns[] = new Column($property);
            }
        }

        return $columns;
    }

    public static function getPrimaryKeyColumns() : array
    {
        $columns = array();

        foreach (static::getColumns() as $column)
        {
            if ($column->isPrimaryKey())
            {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    public static function getTableName()
    {
        return strtolower(
            preg_replace("|([^A-Z])([A-Z])|", "$1_$2",
                preg_replace("|.*\\\\([^\\\\]+)$|", "$1", static::class)));
    }

    public static function getFullEscapedTableName()
    {
        return Database::escapeName(Database::get()->getDatabaseName()).".".Database::escapeName(static::getTableName());
    }

    public static function getPrimaryKeyName()
    {
        return "pk_".static::getTableName();
    }

    public static function getEscapedPrimaryKeyName()
    {
        return Database::escapeName(static::getPrimaryKeyName());
    }

    public static function getForeignKeyName(Column $column, Column $reference) : string
    {
        return "fk_".$column->getTableName()."_".$reference->getTableName();
    }

    public static function getEscapedForeignKeyName(Column $column, Column $reference) : string
    {
        return Database::escapeName(static::getForeignKeyName($column, $reference));
    }

    public static function getUniqueKeyName($columnName)
    {
        return "uniq_".static::getTableName()."_${columnName}";
    }

    public static function getEscapedUniqueKeyName($columnName)
    {
        return Database::escapeName(static::getUniqueKeyName($columnName));
    }

    public static function getCreateTableQuery($onlyIfNotExists = true) : string
    {
        $queryParts = array(
            "CREATE TABLE".($onlyIfNotExists ? " IF NOT EXISTS" : ""),
            static::getFullEscapedTableName(),
            "("
        );

        $columnsAndConstraints = array();
        $primaryKey = array();
        $foreignKeys = array();
        $uniqueColumns = array();

        foreach (static::getColumns() as $column)
        {
            $columnDefinition = array();
            $columnDefinition[] = $column->getEscapedName();
            $columnDefinition[] = $column->getType();

            if ($column->isAutoIncremented())
            {
                $columnDefinition[] = "AUTO_INCREMENT";
            }

            $columnDefinition[] = "COMMENT '".str_replace("'", "\'", $column->getComment())."'";

            $columnsAndConstraints[] = implode(" ", $columnDefinition);

            if ($column->isPrimaryKey())
            {
                $primaryKey[] = $column->getEscapedName();
            }

            if ($column->isForeignKey())
            {
                $foreignKeys[] = $column;
            }

            if ($column->isUnique())
            {
                $uniqueColumns[] = $column;
            }
        }

        if (count($primaryKey))
        {
            $columnsAndConstraints[] = "CONSTRAINT ".static::getEscapedPrimaryKeyName()." PRIMARY KEY (".implode(", ", $primaryKey).")";
        }

        foreach ($foreignKeys as $column)
        {
            $reference = $column->getReference();
            $columnsAndConstraints[] = "CONSTRAINT ".static::getEscapedForeignKeyName($column, $reference)." FOREIGN KEY (".$column->getEscapedName().") REFERENCES ".$reference->getEscapedTableName()." (".$reference->getEscapedName().") ON UPDATE ".$column->getOnReferenceUpdateAction()." ON DELETE ".$column->getOnReferenceDeleteAction();
        }

        foreach ($uniqueColumns as $column)
        {
            $columnsAndConstraints[] = "CONSTRAINT ".static::getEscapedUniqueKeyName($column->getName())." UNIQUE (".$column->getEscapedName().")";
        }

        $queryParts[] = implode(", ", $columnsAndConstraints);
        $queryParts[] = ");";

        // TODO: Integrate engine: InnoDb or MyISAM?

        return implode(" ", $queryParts);
    }

    public static function createTable($onlyIfNotExists = true) : bool
    {
        return boolval(Database::get()->query(static::getCreateTableQuery($onlyIfNotExists)));
    }

    public static function getDropTableQuery($onlyIfExists = true) : string
    {
        $queryParts = array("DROP TABLE");

        if ($onlyIfExists)
        {
            $queryParts[] = "IF EXISTS";
        }

        $queryParts[] = static::getFullEscapedTableName();

        return implode(" ", $queryParts).";";
    }

    public static function dropTable($onlyIfExists = true) : bool
    {
        return boolval(Database::get()->query(static::getDropTableQuery($onlyIfExists)));
    }

    public static function getInsertIntoQuery(array $data)
    {
        $columns = array();
        $values = array();

        foreach ($data as $column => $value)
        {
            $columns[] = Database::escapeName($column);
            $values[] = Database::escapeValue($value);
        }

        $queryParts = array(
            "INSERT INTO",
            static::getFullEscapedTableName(),
            "(",
            implode(", ", $columns),
            ") VALUES (",
            implode(", ", $values),
            ");"
        );

        return implode(" ", $queryParts);
    }

    /**
     * Create a new instance for a new object that doesn't exist in the database.
     * It behaves the same as calling the constructor but with an associative array
     * as unique argument.
     *
     * Auto-incremented values are ignored, because yes they will be automatically incremented :)
     * Missing values are set to their default value when it exists, or null if authorized.
     *
     * @param array $data   The associative array representing the instance values.
     * @return Table        The newly created instance.
     */
    public static function newInstanceFromArray(array $data) : Table
    {
        $constructorArgs = array();

        foreach (static::getColumns() as $column)
        {
            if ($column->isAutoIncremented())
            {
                continue;
            }
            else
            {
                if (array_key_exists($column->getName(), $data))
                {
                    $constructorArgs[] = $data[$column->getName()];
                }
                else
                {
                    // TODO: Get default value if defined or null if allowed
                    throw new \Exception("Missing value for \"".$column->getName()."\" column.");
                }
            }
        }

        $class = new \ReflectionClass(static::class);
        return $class->newInstanceArgs($constructorArgs);
    }

    /**
     * Load an existing object from an associative array into a new PHP instance.
     * A missing value will throw an exception.
     *
     * @param array $data   The associative array representing the instance values.
     * @return Table        The newly created instance.
     */
    public static function loadFromArray(array $data) : Table
    {
        $constructorArgs = array();
        $postConstructorColumns = array();

        foreach (static::getColumns() as $column)
        {
            if (array_key_exists($column->getName(), $data))
            {
                if ($column->isAutoIncremented())
                {
                    $postConstructorColumns[] = $column;
                }
                else if ($column->isForeignKey())
                {
                    $constructorArgs[] =  $column->createReferencedTableLazyInstance($data[$column->getName()]);
                }
                else
                {
                    $constructorArgs[] = $data[$column->getName()];
                }
            }
            else
            {
                throw new \Exception("Missing value for \"".$column->getName()."\" column.");
            }
        }

        $class = new \ReflectionClass(static::class);
        $instance =  $class->newInstanceArgs($constructorArgs);

        foreach ($postConstructorColumns as $column)
        {
            $instance->{$column->getName()} = $column->parseValue($data[$column->getName()]);
        }

        $instance->__newRow = false;

        return $instance;
    }

    /**
     * Create a new instance for a new object that doesn't exist in the database.
     * It behaves the same as calling the constructor but with an associative array
     * as unique argument.
     *
     * Auto-incremented values are ignored, because yes they will be automatically incremented :)
     * Missing values are set to their default value when it exists, or null if authorized.
     *
     * @param array $data   The associative array representing the instance values.
     * @return Table        The newly created instance.
     */
    public static function newLazyInstance(...$primaryKey) : Table
    {
        $class = new \ReflectionClass(static::class);
        $instance = $class->newInstanceWithoutConstructor();
        $instance->__lazy = true;
        $instance->__newRow = false;

        foreach (static::getColumns() as $column)
        {
            if ($column->isPrimaryKey())
            {
                if (count($primaryKey) == 0) {
                    throw new Exception("Can't instanciate a lazy instance: too few primary key values.");
                }

                $instance->{$column->getName()} = $column->parseValue(array_shift($primaryKey));
            }
        }

        if (count($primaryKey) > 0) {
            throw new Exception("Can't instanciate a lazy instance: too much primary key values.");
        }

        return $instance;
    }

    public static function create(array $data) : Table
    {
        $instance = static::newInstanceFromArray($data);
        $instance->save();
        return $instance;
    }

    public static function get(...$primaryKey) : Table
    {
        $filters = new AndFilter();

        foreach (static::getColumns() as $column)
        {
            if ($column->isPrimaryKey())
            {
                if (count($primaryKey) == 0)
                {
                    throw new \Exception("Missing \"".$column->getName()."\" primary key argument.");
                }

                $filters->append(new Equal($column, array_shift($primaryKey)));
            }
        }

        if (count($primaryKey) > 0)
        {
            throw new \Exception("Too many arguments passed: the primary key is composed of only ".count($filters)." argument(s).");
        }

        $results = static::find($filters, 1, 1);

        if (count($results) == 0)
        {
            throw new \Exception("Primary key not found in the database.");
        }

        return $results[0];
    }

    protected static function getWhereClause(Filter $filters) : string
    {
        $strFilters = (string) $filters;

        if (strlen($strFilters) > 0)
        {
            return "WHERE ${strFilters}";
        }
        else
        {
            return "";
        }
    }

    public static function getSelectQuery(?Filter $filters, ?int $pageSize = null, ?int $page = null, ?OrderBy $orderBy = null) : string
    {
        $columns = array();
        foreach (static::getColumns() as $column)
        {
            $columns[] = $column->getEscapedName();
        }

        $queryParts = array(
            "SELECT",
            implode(", ", $columns),
            "FROM",
            static::getFullEscapedTableName()
        );

        if (!is_null($filters))
        {
            $queryParts[] = static::getWhereClause($filters);
        }

        if (!is_null($orderBy))
        {
            $queryParts[] = (string) $orderBy;
        }

        if (!is_null($pageSize))
        {
            $page = is_null($page) || $page == 0 ? 0 : $page - 1;
            $queryParts[] = "LIMIT ${page},${pageSize}";
        }

        return implode(" ", $queryParts).";";
    }

    public static function find(Filter $filters, ?int $pageSize = null, ?int $page = null, ?OrderBy $orderBy = null) : array
    {
        $results = array();
        $rows = Database::get()->query(static::getSelectQuery($filters, $pageSize, $page, $orderBy));

        while ($row = $rows->fetch_assoc())
        {
            $results[] = static::loadFromArray($row);
        }

        return $results;
    }

    public static function getUpdateQuery(array $data, ?Filter $filters) : string
    {
        $update = array();

        foreach ($data as $key => $value)
        {
            $update[] = Database::escapeName($key)." = ".Database::escapeValue($value);
        }

        $queryParts = array(
            "UPDATE",
            static::getFullEscapedTableName(),
            "SET",
            implode(", ", $update)
        );

        if (!is_null($filters))
        {
            $queryParts[] = static::getWhereClause($filters);
        }

        return implode(" ", $queryParts).";";
    }

    public static function getDeleteQuery(?Filter $filters) : string
    {
        $queryParts = array(
            "DELETE FROM",
            static::getFullEscapedTableName()
        );

        if (!is_null($filters))
        {
            $queryParts[] = static::getWhereClause($filters);
        }

        return implode(" ", $queryParts).";";
    }

    public static function count(?Filter $filters = null) : int
    {
        $queryParts = array(
            "SELECT COUNT(*) AS `count` FROM",
            static::getFullEscapedTableName()
        );

        if (!is_null($filters))
        {
            $queryParts[] = static::getWhereClause($filters);
        }

        $rows = Database::get()->query(implode(" ", $queryParts).";");
        $count = null;

        while ($row = $rows->fetch_assoc())
        {
            $count = $row["count"];
            break;
        }

        return $count;
    }
}
