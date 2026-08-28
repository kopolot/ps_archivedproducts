<?php
/**
 * @author    kopolot
 * @copyright kopolot
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0($module)
{
    return $module->installDatabase();
}
