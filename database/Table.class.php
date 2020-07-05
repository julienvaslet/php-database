<?php
// Copyright (c) 2020 Julien Vaslet

namespace database;

require_once(__DIR__."/Column.class.php");
require_once(__DIR__."/Database.class.php");


class Table
{
    protected bool $__newRow;

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
                // TODO: Get default values
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
            // TODO: update...
            // where: primary key or all fields if no primary key defined.
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
                    // TODO: Get default value if defined
                    throw new \Exception("Missing value for \"".$column->getName()."\" column.");
                }
            }
        }

        $class = new \ReflectionClass(static::class);
        return $class->newInstanceArgs($constructorArgs);
    }

    public static function create(array $data) : Table
    {
        $instance = static::newInstanceFromArray($data);
        $instance->save();
        return $instance;
    }
}
