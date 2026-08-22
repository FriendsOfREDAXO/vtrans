<?php

/** @var rex_addon $this */

// REDAXO runs update.php -- not install.php -- when an already installed addon
// is updated through the installer (see addons/install/lib/package/package_update.php).
// The schema in install.php is defined idempotently with rex_sql_table and carries
// the migrations, so running it again is the correct way to bring an existing
// installation up to the current schema. Without this, no update would ever reach
// the database and only a manual reinstall would.
//
// __DIR__ matters here: during an installer update this file is executed from the
// extracted temp directory while the addon directory still holds the previous
// version, so the relative path picks up the new install.php rather than the old one.
require __DIR__ . '/install.php';
