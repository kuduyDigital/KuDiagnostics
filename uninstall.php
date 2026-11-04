<?php
/**
 * Uninstall entry point. Keeps all data unless wp-config.php defines
 * KUDUY_MCP_DELETE_DATA_ON_UNINSTALL as true.
 *
 * @package Kuduy\Mcp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

Kuduy\Mcp\Lifecycle\Uninstaller::run( defined( 'KUDUY_MCP_DELETE_DATA_ON_UNINSTALL' ) && true === KUDUY_MCP_DELETE_DATA_ON_UNINSTALL );
