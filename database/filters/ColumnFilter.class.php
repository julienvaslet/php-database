<?php
// Copyright (c) 2020 Julien Vaslet

namespace database\filters;

require_once(__DIR__."/Filter.class.php");
require_once(__DIR__."/../Column.class.php");

use database\Column;


class ColumnFilter extends Filter
{
    protected Column $column;

    public function __construct(Column $column)
    {
        parent::__construct();
        $this->column = $column;
    }
}
