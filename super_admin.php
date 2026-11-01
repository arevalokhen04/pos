<?php
/**
 * MARINA'S HARDWARE POS - Super Admin dashboard
 * Loads the shared admin app with $IS_SUPER = true,
 * which unlocks User Management and the full approvals queue.
 */
$IS_SUPER = true;
require __DIR__ . '/admin.php';
