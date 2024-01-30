<?php

namespace App\Components;

/**
 * Class BaseComponent
 *
 * @package App\Components
 */
class BaseComponent
{
    /**
     * Dynamic getter to handle the translations
     *
     * @param $method
     * @param $params
     *
     * @return mixed
     */
    public function __call($method, $params)
    {
        $attribute = lcfirst(substr($method, 3));
        if (strncasecmp($method, "get", 3) === 0) {
            $value = $this->$attribute;
            
            return $this->translateIfNeeded($value);
        }
    }
    
    /**
     * Translate an attribute if it has the "__" prefix and is a string
     * This is a recursive function
     *
     * @param $value
     *
     * @return array|null|string
     */
    protected function translateIfNeeded($value)
    {
        if (is_string($value) && substr($value, 0, 2) === '__') {
            $value = _f(substr($value, 2));
        }
        if (is_array($value)) {
            foreach ($value as $key => $row) {
                $value[$key] = $this->translateIfNeeded($row);
            }
        }
        
        return $value;
    }
}
