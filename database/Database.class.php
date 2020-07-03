<?php
// Copyright (c) 2020 Julien Vaslet

namespace database;


class Database
{
    protected static ?Database $instance = null;
    protected \mysqli $db;
    protected string $databaseName;
    protected bool $debug = false;

    protected function __construct(string $host, int $port, string $user, string $password, string $database)
    {
        if (!is_null(Database::$instance))
        {
            throw new \Exception("Unable to create a database connection because a database is already configured.");
        }

        $this->databaseName = $database;
        $this->db = @(new \mysqli($host, $user, $password, $database, $port));

        if ($this->db->connect_errno != 0)
        {
            throw new \Exception("Unable to connect to the database (".$this->db->connect_errno."): \"".$this->db->connect_error."\"");
        }

        Database::$instance = $this;
    }

    public function __destruct()
    {
        if (Database::$instance == $this)
        {
            Database::$instance = null;
        }

        $this->db->close();
    }

    public function getDatabaseName() : string
    {
        return $this->databaseName;
    }

    public function enableDebug()
    {
        $this->debug = true;
    }

    public function disableDebug()
    {
        $this->debug = false;
    }

    public function query(string $query)
    {
        $result = $this->db->query($query);

        if ($result === false)
        {
            $message = "SQL error ".$this->db->errno.": ".$this->db->error;

            if ($this->debug === true)
            {
                $message .= "\nQuery: ${query}";
            }

            throw new \Exception($message);
        }

        return $result;
    }

    public static function configure(string $host, int $port, string $user, string $password, string $database) : Database
    {
        return new Database($host, $port, $user, $password, $database);
    }

    public static final function get() : Database
    {
        return Database::$instance;
    }

    public static function escapeName(string $name) : string
    {
        return "`${name}`";
    }
}
