<?php
// Copyright (c) 2020 Julien Vaslet

namespace database;

require_once(__DIR__."/Column.class.php");


class OrderBy
{
    const Desc = "DESC";
    const Asc = "ASC";

    protected Column $column;
    protected string $order;
    protected ?OrderBy $subOrder;

    public function __construct(Column $column, string $order = OrderBy::Asc)
    {
        $this->column = $column;
        $this->order = ($order == OrderBy::Desc) ? OrderBy::Desc : OrderBy::Asc;
        $this->subOrder = null;
    }

    public function addSubOrder(Column $column, string $order = OrderBy::Asc) : OrderBy
    {
        if (is_null($this->subOrder))
        {
            $this->subOrder = new OrderBy($column, $order);
        }
        else
        {
            $this->subOrder->addSubOrder($column, $order);
        }

        // Allow chaining
        return $this;
    }

    public function __toString() : string
    {
        $orderBy = $this;
        $orders = array();

        do
        {
            $orders[] = $orderBy->column->getEscapedName()." ".$orderBy->order;
            $orderBy = $orderBy->subOrder;
        }
        while (!is_null($orderBy));

        return "ORDER BY ".implode(", ", $orders);
    }
}
