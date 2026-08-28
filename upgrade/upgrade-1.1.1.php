<?php
/**
 * @author    kopolot
 * @copyright kopolot
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_1($module)
{
    $overrideFile = _PS_OVERRIDE_DIR_ . 'controllers/front/ProductController.php';

    if (file_exists($overrideFile)) {
        $contents = Tools::file_get_contents($overrideFile);
        if ($contents !== false && strpos($contents, 'ps_archivedproducts') !== false) {
            unlink($overrideFile);
            Tools::generateIndex();
        }
    }

    return $module->installDatabase();
}
