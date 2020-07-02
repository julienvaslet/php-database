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
        return "`".Database::get()->getDatabaseName()."`.`".static::getTableName()."`";
    }

    public static function getCreateTableQuery($onlyIfNotExists = true) : string
    {
        $queryParts = array(
            "CREATE TABLE".($onlyIfNotExists ? " IF NOT EXISTS" : ""),
            static::getFullEscapedTableName(),
            "("
        );

        $columns = array();
        foreach (static::getColumns() as $column)
        {
            $columnDefinition = array();
            $columnDefinition[] = $column->getEscapedName();
            $columnDefinition[] = $column->getType();

            if ($column->isAutoIncremented())
            {
                $columnDefinition[] = "AUTO_INCREMENT";
            }

            // TODO: Handle unique and primary key here.

            $columnDefinition[] = "COMMENT '".str_replace("'", "\'", $column->getComment())."'";

            $columns[] = implode(" ", $columnDefinition);
        }

        $queryParts[] = implode(", ", $columns);
        $queryParts[] = ");";

        // TODO: Integrate engine: InnoDb or MyISAM?

        return implode(" ", $queryParts);
    }

    public static function createTable($onlyIfNotExists = true)
    {
    }
}
