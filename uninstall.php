<?php
/** Preserve financial/audit data and role assignments on uninstall.
 * Destructive data removal requires a separate, explicit retention policy.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
