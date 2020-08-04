<?php

require_once(__DIR__."/database/database.php");

use \database\Database;
use \database\Table;
use \database\OrderBy;
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


class Brand extends Table
{
    /**
     * @autoIncrement
     * @unsigned
     * @primaryKey
     * The brand identifier.
     */
    protected int $id;

    /**
     * @varchar(32)
     * The brand's name.
     */
    protected string $name;
}


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
     * @onUpdate(cascade)
     * @onDelete(restrict)
     * The car's brand.
     */
    protected Brand $brand;

    /**
     * @decimal(9,2)
     * The car average price.
     */
    protected float $price;

    public function getId() : int
    {
        return $this->id;
    }

    public function getBrand() : Brand
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
Brand::dropTable();
Brand::createTable();
Car::createTable();

$toyota = new Brand("Toyota");
$toyota->save();
$chevrolet = new Brand("Chevrolet");
$chevrolet->save();
$ford = new Brand("Ford");
$ford->save();

$car = new Car($toyota, 35000.0);
$car->save();

$car2 = Car::create(
    array(
        "brand" => $ford,
        "price" => 45000.0
    )
);

$car3 = Car::create(
    array(
        "brand" => $chevrolet,
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
        new DifferentFrom(Car::column("brand"), $ford)
    ),
    10,  // page size
    1,   // page number
    new OrderBy(Car::column("price"), OrderBy::Asc)
);
