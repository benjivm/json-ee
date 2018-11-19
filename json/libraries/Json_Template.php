<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class Json_Template extends EE_Template
{
    /**
     * A copy of EE's TMPL object.
     *
     * @var EE_Template
     */
    protected $TMPL;

    /**
     * Variables collected from parse_variables or parse_variables row.
     *
     * @var array
     */
    public $variables = [];

    public function __construct()
    {
        parent::__construct();

        // Store a local reference to the "real" TMPL object, so it can be restored on __destruct
        $this->TMPL = &ee()->TMPL;

        // Override the "real" TMPL object
        ee()->TMPL = &$this;
    }

    public function __destruct()
    {
        // Restore the "real" TMPL object
        ee()->TMPL = &$this->TMPL;
    }

    public function parse_variables($tagdata, $variables, $enable_backspace = true)
    {
        $output = parent::parse_variables($tagdata, $variables, $enable_backspace);
        $this->variables = $variables;

        return $output;
    }

    public function parse_variables_row($tagdata, $variables, $solo = true)
    {
        $this->variables = $variables;

        return parent::parse_variables_row($tagdata, $variables, $solo);
    }
}
