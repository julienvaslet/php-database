<?php

require_once(__DIR__."/database/database.php");

use \database\Database;
use \database\Table;
use \database\filters\AndFilter;
use \database\filters\GreaterThan;
use \database\filters\DifferentFrom;

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
     * @decimal(9,2)
     * The car average price.
     */
    protected float $price;

    public function getId() : int
    {
        return $this->id;
    }

    public function getBrand() : string
    {
        return $this->brand;
    }

    public function getPrice() : float
    {
        return $this->price;
    }

    public function setPrice(float $price)
    {
        $this->price = $price;
    }
}


Car::dropTable();
Car::createTable();

$car = new Car("Toyota", 35000.0);
$car->save();

$car2 = Car::create(
    array(
        "brand" => "Ford",
        "price" => 45000.0
    )
);

$car3 = Car::create(
    array(
        "brand" => "Chevrolet",
        "price" => 20000.0
    )
);

$carId = $car2->getId();
$car = Car::get($carId);

$car->setPrice(40000.0);
$car->save();

$cars = Car::find(
    new AndFilter(
        new GreaterThan(Car::column("price"), 25000.0),
        new DifferentFrom(Car::column("brand"), "Ford")
    ),
    10,  // page size
    1    // page number
);
