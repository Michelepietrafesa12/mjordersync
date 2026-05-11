<?php
/**
 * MJ Order Sync - Upgrade to 1.2.0
 *
 * Swap the order-update hook subscription on existing installs:
 *   actionObjectOrderUpdateAfter  -> fires on every Order save (5-10x per order)
 *   actionOrderStatusUpdate       -> fires only on real status transitions
 *
 * This runs automatically when PrestaShop detects a version bump. Without it
 * the constant in mjordersync.php would change but the row in ps_hook_module
 * would still point at the old hook, so the receiver would keep getting
 * duplicate webhooks until a manual reinstall.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0($module)
{
    // Unregister the noisy hook if it's still attached.
    $module->unregisterHook('actionObjectOrderUpdateAfter');

    // Register the precise hook. registerHook is idempotent: if already there
    // PrestaShop returns true without inserting a duplicate row.
    if (!$module->registerHook('actionOrderStatusUpdate')) {
        return false;
    }

    return true;
}
