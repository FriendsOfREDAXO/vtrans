<?php

/** @var rex_addon $this */

rex_sql_table::get(rex::getTable('vtrans'))->drop();
rex_sql_table::get(rex::getTable('vtrans_connection'))->drop();

rex_config::removeNamespace('vtrans');

rex_dir::delete(rex_path::addonData('vtrans'));
