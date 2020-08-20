<?php
// Copyright (c) Julien Vaslet

namespace database;

/*
 * This class is intented to be use with the gateway module:
 * https://github.com/julienvaslet/php-gateway
 *
 * Please import the gateway module first, and this class will then be defined.
 */

if (interface_exists("\gateway\Serializable"))
{
    require_once(__DIR__."/Table.class.php");

    abstract class SerializableTable extends Table implements \gateway\Serializable
    {
        public static function getSerializableAttributes() : array
        {
            $attributes = array();

            foreach (static::getColumns() as $column)
            {
                $attributes[$column->getName()] = $column->getPhpType();
            }

            return $attributes;
        }

        public final function serialize() : array
        {
            $data = array();
            $attributes = array_keys(self::getSerializableAttributes());

            foreach ($this as $attribute => $value)
            {
                if (in_array($attribute, $attributes))
                {
                    $data[$attribute] = static::serializeData($value);
                }
            }

            return $data;
        }

        public function serializeResponse() : string
        {
            return json_encode($this->serialize());
        }

        public static final function serializeData($data)
        {
            if ($data instanceof \gateway\Serializable)
            {
                $data = $data->serialize();
            }
            else if (is_array($data))
            {
                $data = array_map(array(static::class, "serializeData"), $data);
            }

            return $data;
        }

        public static final function deserialize(array $data) : \gateway\Serializable
        {
            $attributes = static::getSerializableAttributes();
            $instance = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
            $primaryKeyLoaded = true;
            $allColumnsExceptPrimaryKeyLoaded = true;
            $missingColumns = array();

            foreach (static::getColumns() as $column)
            {
                $value = null;

                if (array_key_exists($column->getName(), $data))
                {
                    if ($column->isForeignKey())
                    {
                        $value =  $column->createReferencedTableLazyInstance($data[$column->getName()]);
                    }
                    else
                    {
                        $value = \gateway\Route::castValue($column->getPhpType(), $data[$column->getName()]);
                    }
                }
                else
                {
                    // TODO: handle default values when implemented
                    if ($column->isPrimaryKey())
                    {
                        $primaryKeyLoaded = false;
                    }
                    else
                    {
                        $allColumnsExceptPrimaryKeyLoaded = false;
                        $missingColumns[] = $column->getName();
                    }

                    continue;
                }

                $instance->{$column->getName()} = $value;
            }

            if (!$allColumnsExceptPrimaryKeyLoaded && !$primaryKeyLoaded)
            {
                throw new \Exception("Missing parameters for ".static::class." object: ".implode(", ", $missingColumns).".");
            }

            $instance->__lazy = !$allColumnsExceptPrimaryKeyLoaded;

            // TODO: That is not entirely correct, but that's how it is handled for now.
            $instance->__newRow = !$primaryKeyLoaded;

            return $instance;
        }
    }
}
