<?php
// Copyright (c) 2020 Julien Vaslet

namespace database\filters;

require_once(__DIR__."/ColumnFilter.class.php");
require_once(__DIR__."/../Database.class.php");

use database\Database;
use database\Column;


class GreaterThan extends ColumnFilter
{
    protected $value;

    public function __construct(Column $column, $value)
    {
        parent::__construct($column);
        $this->value = $column->parseValue($value);
    }

    public function __toString() : string
    {
        return $this->column->getEscapedName()." > ".Database::escapeValue($this->value);
    }
}
