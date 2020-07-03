<?php
// Copyright (c) 2020 Julien Vaslet

namespace database;

require_once(__DIR__."/Column.class.php");
require_once(__DIR__."/Database.class.php");


class Table
{
    public function __construct()
    {

    }

    protected static function getColumns() : array
    {
        $columns = array();
        $class = new \ReflectionClass(static::class);

        foreach ($class->getProperties() as $property)
        {
            $columns[] = new Column($property);
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
}
