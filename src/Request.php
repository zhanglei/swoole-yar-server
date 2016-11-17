<?php
namespace Easth\Server;

use ArrayAccess;

class Request implements ArrayAccess
{
    protected $values = [];

    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function offsetExists($key)
    {
        return array_key_exists($key, $this->values);
    }

    public function offsetGet($key)
    {
        return $this->values[$key];
    }

    public function offsetSet($key, $value)
    {
        if (is_null($key)) {
            $this->valuse[] = $value;
        } else {
            $this->valuse[$key] = $value;
        }
    }

    public function offsetUnset($key)
    {
        unset($this->values[$key]);
    }
}