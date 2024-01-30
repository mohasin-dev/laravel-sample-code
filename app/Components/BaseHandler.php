<?php

namespace App\Components;

use Illuminate\Support\Str;

/**
 * Class BaseHandler
 *
 * Provides general parent methods for the components' handlers
 *
 * @package App\Components
 */
class BaseHandler
{
    /**
     * The component type
     *
     * @var null|string
     */
    public $component = null;
    
    /**
     * Gets a single component instance
     *
     * @param string $name
     * @return mixed - a component single instance
     */
    public function getSingle($name)
    {
        // If there is -/_ in name we look for camelcase named component
        $name = Str::camel($name);

        return fd_get_single_component($this->component, ucfirst($name));
    }
    
    /**
     * Return an array of all single components' attributes
     *
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function list()
    {
        return fd_get_list_components($this->component, false, 'id');
    }
}
