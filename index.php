<?php

require_once(__DIR__."/database/database.php");

use \database\Database;
use \database\Table;

/*
 * This is a sample usage of the database module.
 * A MySQL database is required to run this file.
 */

Database::configure(
    "127.0.0.1",
    3306,
    "user",
    "password",
    "test_db"
);

Database::get()->enableDebug();


class Car extends Table
{
    /**
     * @autoIncrement
     * @unsigned
     * @primaryKey
     * The car identifier.
     */
    protected int $id;

    /**
     * @varchar(32)
     * The car's brand.
     */
    protected string $brand;

    /**
     * @decimal(5,2)
     * The car average price.
     */
    protected float $price;
}


Car::createTable();
