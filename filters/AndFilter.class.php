<?php
// Copyright (c) 2020 Julien Vaslet

namespace database\filters;

require_once(__DIR__."/Filter.class.php");


class AndFilter extends Filter implements \Countable
{
    protected array $filters;

    public function __construct(Filter ...$filters)
    {
        parent::__construct();
        $this->filters = $filters;
    }

    public function count() : int
    {
        return count($this->filters);
    }

    public function append(Filter $filter)
    {
        $this->filters[] = $filter;
    }

    public function __toString() : string
    {
        return implode(" AND ", $this->filters);
    }
}
