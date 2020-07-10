<?php
// Copyright (c) 2020 Julien Vaslet

namespace database;

require_once(__DIR__."/Column.class.php");
require_once(__DIR__."/Database.class.php");
require_once(__DIR__."/filters/Filter.class.php");
require_once(__DIR__."/filters/EqualFilter.class.php");

use database\filters\Filter;
use database\filters\EqualFilter;


class Table
{
    protected bool $__newRow;

    /**
     * Create a new instance for a new object that doesn't exist in the database.
     * Parameters of this constructor are the class attributes in their order of definition.
     * Auto-incremented values are ignored, because yes they will be automatically incremented :)
     */
    public function __construct(...$values)
    {
        $this->__newRow = true;
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

    public function save()
    {
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
            $filters = array();

            foreach (static::getColumns() as $column)
            {
                if ($column->isPrimaryKey())
                {
                    $filters[] = new EqualFilter($column, $this->{$column->getName()});
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
                    $filters[] = new EqualFilter($column, $this->{$column->getName()});
                }
            }

            Database::get()->query(static::getUpdateQuery($data, $filters));
        }
    }

    protected static function getColumns() : array
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

    public static function getTableName()
    {
        return strtolower(preg_replace("|([^A-Z])([A-Z])|", "$1_$2", static::class));
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

            if ($column->isUnique())
            {
                $uniqueColumns[] = $column;
            }
        }

        if (count($primaryKey))
        {
            $columnsAndConstraints[] = "CONSTRAINT ".static::getEscapedPrimaryKeyName()." PRIMARY KEY (".implode(", ", $primaryKey).")";
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

    public static function create(array $data) : Table
    {
        $instance = static::newInstanceFromArray($data);
        $instance->save();
        return $instance;
    }

    public static function get(...$primaryKey) : Table
    {
        $filters = array();

        foreach (static::getColumns() as $column)
        {
            if ($column->isPrimaryKey())
            {
                if (count($primaryKey) == 0)
                {
                    throw new \Exception("Missing \"".$column->getName()."\" primary key argument.");
                }

                $filters[] = new EqualFilter($column, array_shift($primaryKey));
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

    protected static function getWhereClause(array $filters) : string
    {
        $parts = array();

        // TODO: Implement the combinatory logic
        foreach ($filters as $filter)
        {
            if ($filter instanceof Filter)
            {
                $parts[] = $filter->__toString();
            }
        }

        if (count($parts))
        {
            return "WHERE ".implode(" AND ", $parts);
        }
        else
        {
            return "";
        }
    }

    public static function getSelectQuery(array $filters, ?int $pageSize = null, ?int $page = null) : string
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

        if (count($filters) > 0)
        {
            $queryParts[] = static::getWhereClause($filters);
        }

        if (!is_null($pageSize))
        {
            $page = is_null($page) || $page == 0 ? 0 : $page - 1;
            $queryParts[] = "LIMIT ${page},${pageSize}";
        }

        return implode(" ", $queryParts).";";
    }

    public static function find(array $filters, ?int $pageSize = null, ?int $page = null) : array
    {
        $results = array();
        $rows = Database::get()->query(static::getSelectQuery($filters, $pageSize, $page));

        while ($row = $rows->fetch_assoc())
        {
            $results[] = static::loadFromArray($row);
        }

        return $results;
    }

    public static function getUpdateQuery(array $data, array $filters) : string
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

        if (count($filters))
        {
            $queryParts[] = static::getWhereClause($filters);
        }

        return implode(" ", $queryParts).";";
    }
}
