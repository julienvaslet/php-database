<?php
// Copyright (c) 2020 Julien Vaslet

namespace database;


class Column
{
    protected string $name;
    protected string $type;
    protected string $comment;

    protected bool $autoIncrement;
    protected bool $unique;
    protected bool $primaryKey;

    // $default (default value)
    // $references (foreignKey)


    public function __construct(\ReflectionProperty $property)
    {
        $this->name = $property->getName();

        if (!$property->hasType())
        {
            throw new \Exception("Missing type for ".$property->getDeclaringClass()->getName()."::$".$this->name." class attribute!");
        }

        $attributes = static::parseDocComment($property->getDocComment());

        $this->comment = $attributes["comment"];
        $this->unique = array_key_exists("unique", $attributes) && $attributes["unique"] == true;
        $this->primaryKey = array_key_exists("primaryKey", $attributes) && $attributes["primaryKey"] == true;
        $this->type = static::getMySqlType($property->getType(), $attributes);

        $this->autoIncrement = array_key_exists("autoIncrement", $attributes) && $attributes["autoIncrement"] == true;

        if ($this->autoIncrement && $property->getType()->getName() != "int")
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
        return "`".$this->name."`";
    }

    public function getType() : string
    {
        return $this->type;
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

    // TODO: Handle more types
    protected static function getMySqlType(\ReflectionType $reflectionType, array $attributes) : string
    {
        $phpType = $reflectionType->getName();
        $null = $reflectionType->allowsNull();
        $unsigned = array_key_exists("unsigned", $attributes) && $attributes["unsigned"] == true;
        $type = array_key_exists("type", $attributes) ? $attributes["type"] : null;

        $typedef = array();

        if ($unsigned)
        {
            $typedef[] = "UNSIGNED";
        }

        $typesFromPhp = array(
            "string" => array("VARCHAR", "TEXT", "BLOB"),
            "float" => array("FLOAT", "DOUBLE", "DECIMAL"),
            "int" => array("INT"),
            "bool" => array("BOOLEAN", "INT")
        );

        if (is_null($type))
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
