<?php
// Copyright (c) 2020 Julien Vaslet

namespace database;

require_once(__DIR__."/Table.class.php");


class Column
{
    protected \ReflectionClass $table;
    protected string $tableName;
    protected string $name;
    protected \ReflectionType $phpType;
    protected string $sqlType;
    protected string $comment;

    protected bool $autoIncrement;
    protected bool $unique;
    protected bool $primaryKey;
    protected ?Column $references;
    protected string $onReferenceUpdate;
    protected string $onReferenceDelete;

    // $default (default value)


    public function __construct(\ReflectionProperty $property)
    {
        $this->table = $property->getDeclaringClass();
        $this->tableName = $this->table->getMethod("getTableName")->invoke(null);
        $this->name = $property->getName();

        if (!$property->hasType())
        {
            throw new \Exception("Missing type for ".$this->table->getName()."::$".$this->name." class attribute!");
        }

        $attributes = static::parseDocComment($property->getDocComment());

        $this->comment = $attributes["comment"];
        $this->unique = array_key_exists("unique", $attributes) && $attributes["unique"] == true;
        $this->primaryKey = array_key_exists("primaryKey", $attributes) && $attributes["primaryKey"] == true;
        $this->references = null;
        $this->onReferenceUpdate = "NO ACTION";
        $this->onReferenceDelete = "NO ACTION";
        $this->phpType = $property->getType();

        if ($this->phpType->isBuiltin())
        {
            $this->sqlType = static::getMySqlType($this->phpType, $attributes);
        }
        else
        {
            $typeClass = new \ReflectionClass($this->phpType->getName());
            if ($typeClass->isSubclassOf(Table::class))
            {
                $foreignColumns = $typeClass->getMethod("getPrimaryKeyColumns")->invoke(null);

                if (count($foreignColumns) != 1)
                {
                    throw new \Exception("Only single column foreign keys are supported for now, the referenced class has ".count($foreignColumns)." primary key columns.");
                }

                $this->references = $foreignColumns[0];
                $this->onReferenceUpdate = array_key_exists("onUpdate", $attributes) ? $attributes["onUpdate"] : "NO ACTION";
                $this->onReferenceDelete = array_key_exists("onDelete", $attributes) ? $attributes["onDelete"] : "NO ACTION";

                $this->sqlType = $foreignColumns[0]->getType();
            }
        }

        $this->autoIncrement = array_key_exists("autoIncrement", $attributes) && $attributes["autoIncrement"] == true;

        if ($this->autoIncrement && $this->phpType->getName() != "int")
        {
            throw new \Exception("MySQL auto-increment option can only be used on integer.");
        }
    }

    public function getName() : string
    {
        return $this->name;
    }

    public function getEscapedName() : string
    {
        return Database::escapeName($this->name);
    }

    public function getTable() : \ReflectionClass
    {
        return $this->table;
    }

    public function getTableName() : string
    {
        return $this->tableName;
    }

    public function getEscapedTableName() : string
    {
        return Database::escapeName($this->tableName);
    }

    public function getFullName() : string
    {
        return $this->tableName.".".$this->name;
    }

    public function getFullEscapedName() : string
    {
        return Database::escapeName($this->tableName).".".Database::escapeName($this->name);
    }

    public function getType() : string
    {
        return $this->sqlType;
    }

    public function getComment() : string
    {
        return $this->comment;
    }

    public function isAutoIncremented() : bool
    {
        return $this->autoIncrement;
    }

    public function isUnique() : bool
    {
        return $this->unique;
    }

    public function isPrimaryKey() : bool
    {
        return $this->primaryKey;
    }

    public function isForeignKey() : bool
    {
        return !is_null($this->references);
    }

    public function getReference() : ?Column
    {
        return $this->references;
    }

    public function getOnReferenceUpdateAction() : string
    {
        return $this->onReferenceUpdate;
    }

    public function getOnReferenceDeleteAction() : string
    {
        return $this->onReferenceDelete;
    }

    public function createReferencedTableLazyInstance($primaryKey) : Table
    {
        if (!$this->isForeignKey())
        {
            throw new Exception("Can't create an instance for a referenced table without a foreign key definition.");
        }

        return $this->references->getTable()->getMethod("newLazyInstance")->invokeArgs(null, array($primaryKey));
    }

    public function parseValue($value)
    {
        if (is_null($value))
        {
            if (!$this->phpType->allowsNull())
            {
                throw new \Exception("Column ".$this->getName()." can't be null.");
            }
        }
        else
        {
            switch ($this->phpType->getName())
            {
                case "string":
                {
                    $value = strval($value);
                    break;
                }

                case "int":
                {
                    if (!is_int($value) && !(is_string($value) && preg_match("/^(?:\+-)?[0-9]+$/", $value)))
                    {
                        throw new \Exception("Column ".$this->getName()." must be an integer, ".gettype($value)." provided.");
                    }

                    $value = intval($value);
                    break;
                }

                case "float":
                {
                    if (!is_float($value) && !(is_string($value) && preg_match("/^(?:\+-)?[0-9]*\.[0-9]*(?:[eE][0-9]+)?$/", $value)))
                    {
                        throw new \Exception("Column ".$this->getName()." must be a float, ".gettype($value)." provided.");
                    }

                    $value = floatval($value);
                    break;
                }

                case "bool":
                {
                    if (is_string($value))
                    {
                        $value = strtolower($value);
                    }

                    if (!is_bool($value) && !(is_string($value) && preg_match("/^(?:1|0|true|false)$/", $value)))
                    {
                        throw new \Exception("Column ".$this->getName()." must be a boolean, ".gettype($value)." provided.");
                    }

                    if (is_string($value))
                    {
                        $value = !in_array($value, array("0", "false"));
                    }
                    else
                    {
                        $value = boolval($value);
                    }
                    break;
                }

                default:
                {
                    // TODO: Handle custom types.
                    break;
                }
            }
        }

        return $value;
    }

    // TODO: Handle more types
    protected static function getMySqlType(\ReflectionType $reflectionType, array $attributes) : string
    {
        $phpType = $reflectionType->getName();
        $null = $reflectionType->allowsNull();
        $unsigned = array_key_exists("unsigned", $attributes) && $attributes["unsigned"] == true;
        $type = array_key_exists("type", $attributes) ? $attributes["type"] : null;

        $typedef = array();

        $typesFromPhp = array(
            "string" => array("VARCHAR", "TEXT", "BLOB"),
            "float" => array("FLOAT", "DOUBLE", "DECIMAL"),
            "int" => array("INT"),
            "bool" => array("BOOLEAN", "INT")
        );

        if (is_null($type))
        {
            if (array_key_exists($phpType, $typesFromPhp))
            {
                $type = $typesFromPhp[$phpType][0];

                // Default case for varchar
                if ($phpType == "string")
                {
                    $type .= "(32)";
                }
            }
            else
            {
                throw new \Exception("Invalid type \"${phpType}\": it must either inherit ".Table::class." or be a base type (string, int, float, bool).");
            }
        }
        else
        {
            if (!in_array($type[0], $typesFromPhp[$phpType]))
            {
               throw new \Exception("MySQL defined type \"".$type[0]."\" doesn't match the PHP one \"${phpType}\"");
            }

            if (count($type) == 1)
            {
                $type = $type[0];
            }
            else
            {
                $type = $type[0]."(".$type[1].")";
            }
        }

        $typedef[] = $type;

        if ($unsigned)
        {
            $typedef[] = "UNSIGNED";
        }

        $typedef[] = $null ? "NULL" : "NOT NULL";

        return implode(" ", $typedef);
    }

    /**
     * Extracts annotation from the doc comment.
     */
    protected static function parseDocComment(string $docComment) : array
    {
        $attributes = array();
        $lines = array_map(function($line){ return trim($line, " */\t\0\x0B"); }, preg_split("/\r?\n|\r/", $docComment));
        $comment = array();

        foreach ($lines as $line)
        {
            if (preg_match("|^@(?P<option>[^\(]+)(?:\((?P<value>[^\)]+)\))?$|", $line, $matches))
            {
                switch ($matches["option"])
                {
                    case "varchar":
                    {
                        if (preg_match("|^[0-9]+$|", $matches["value"]))
                        {
                            $attributes["type"] = array("VARCHAR", $matches["value"]);
                        }
                        else
                        {
                            $attributes["type"] = array("VARCHAR", "32");
                        }

                        break;
                    }

                    case "decimal":
                    {
                        if (!preg_match("|^[0-9]+,[0-9]+$|", $matches["value"]))
                        {
                            throw new \Exception("Decimal MySQL type must define both integer and floating lengths.");
                        }

                        $attributes["type"] = array("DECIMAL", $matches["value"]);
                        break;
                    }

                    case "tinyint":
                    case "smallint":
                    case "int":
                    case "mediumint":
                    case "bigint":
                    {
                        $sizes = array(
                            "tinyint" => 1,
                            "smallint" => 2,
                            "int" => 3,
                            "mediumint" => 4,
                            "bigint" => 8
                        );

                        if (preg_match("|^[0-9]+$|", $matches["value"]))
                        {
                            $attributes["type"] = array("INT", $matches["value"]);
                        }
                        else
                        {
                            $attributes["type"] = array("INT", $sizes[$matches["option"]]);
                        }

                        break;
                    }

                    case "tinytext":
                    case "text":
                    case "mediumtext":
                    case "longtext":
                    case "tinyblob":
                    case "blob":
                    case "mediumblob":
                    case "longblob":
                    {
                        $attributes["type"] = array(strtoupper($matches["option"]));
                        break;
                    }

                    case "autoIncrement":
                    case "primaryKey":
                    case "unique":
                    case "unsigned":
                    {
                        $attributes[$matches["option"]] = true;
                        break;
                    }

                    case "onUpdate":
                    case "onDelete":
                    {
                        if (!preg_match("/^CASCADE|RESTRICT|NO[ _]ACTION|SET[ _]NULL|SET[ _]DEFAULT$/i", $matches["value"]))
                        {
                            throw new Exception("Invalid reference action \"".$matches["value"]."\", it must be one of: CASCADE, RESTRICT, NO ACTION, SET NULL or SET DEFAULT.");
                        }

                        $attributes[$matches["option"]] = str_replace("_", " ", strtoupper($matches["value"]));
                        break;
                    }

                    default:
                    {
                        $comment[] = $line;
                        break;
                    }
                }
            }
            else
            {
                $comment[] = $line;
            }
        }

        $attributes["comment"] = trim(implode(" ", $comment));

        return $attributes;
    }
}
