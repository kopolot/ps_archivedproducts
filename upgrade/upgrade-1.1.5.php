<?php
/**
 * @author    kopolot
 * @copyright kopolot
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_5($module)
{
    $reflection = new ReflectionClass($module);
    $method = $reflection->getMethod('applyArchivedRedirectToInactiveProducts');
    $method->setAccessible(true);
    $method->invoke($module);

    return $module->registerHook('actionProductActivation');
}
