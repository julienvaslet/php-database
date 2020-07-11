<?php
// Copyright (c) 2020 Julien Vaslet

namespace database\filters;

require_once(__DIR__."/Filter.class.php");


abstract class Filter
{
    public function __construct()
    {
    }

    public function __toString() : string
    {
        throw new \Exception("Filter \"".static::class."\" is not correctly implemented, it must implement the __toString() method.");
    }
}
