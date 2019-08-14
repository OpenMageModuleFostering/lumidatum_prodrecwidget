<?php

class Lumidatum_ProdRecWidget_Model_Options
{
    public function toOptionArray()
    {
        return array(
            array('value' => '1', 'label' => 'Yes'),
            array('value' => '0', 'label' => 'No'),
        );
    }
}