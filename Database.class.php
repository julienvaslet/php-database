<?php
// Copyright (c) 2020 Julien Vaslet

namespace database;

require_once(__DIR__."/Table.class.php");


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

    public function getLastInsertedId() : int
    {
        return $this->db->insert_id;
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
        if (preg_match("/^`[^`]`\$/", $name))
        {
            return $name;
        }

        return "`${name}`";
    }

    public static function escapeValue($value) : string
    {
        $escapedValue = $value;

        if (is_null($value))
        {
            $escapedValue = "NULL";
        }
        else if (is_string($value))
        {
            $escapedValue = preg_replace("/'/", "\\'", $value);
            $encoding = mb_detect_encoding($escapedValue, mb_detect_order(), true);

            if($encoding != "UTF-8")
            {
                $escapedValue = "_utf8'".mb_convert_encoding($escapedValue, "UTF-8", $encoding)."'";
            }
            else
            {
                $escapedValue = "_utf8'".$escapedValue."'";
            }
        }
        else if (is_bool($value))
        {
            $escapedValue = ($value === true) ? "1" : "0";
        }
        else if ($value instanceof \DateTime)
        {
            $escapedValue = "_utf8'".$value->format("Y-m-d H:i:s")."'";
        }
        else if ($value instanceof Table)
        {
            $primaryKey = $value->getPrimaryKey();

            if (count($primaryKey) != 1)
            {
                throw new Exception("Foreign key target field must be only 1 column.");
            }

            return Database::escapeValue($primaryKey[0]);
        }

        return $escapedValue;
    }
}
