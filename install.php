<?php

/** @var rex_addon $this */

// Connection configuration table.
rex_sql_table::get(rex::getTable('vtrans_connection'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('key', 'varchar(191)', false))
    ->ensureColumn(new rex_sql_column('label', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('provider', 'varchar(100)', false))
    ->ensureColumn(new rex_sql_column('api_key', 'text', true))
    ->ensureColumn(new rex_sql_column('api_url', 'varchar(500)', false, ''))
    ->ensureColumn(new rex_sql_column('system_prompt', 'text', true))
    ->ensureColumn(new rex_sql_column('timeout', 'int(10) unsigned', false, '30'))
    ->ensureColumn(new rex_sql_column('max_chars', 'int(10) unsigned', true, null))
    ->ensureColumn(new rex_sql_column('debug', 'tinyint(1)', false, '0'))
    ->ensureColumn(new rex_sql_column('params', 'text', true))
    ->ensureColumn(new rex_sql_column('is_default', 'tinyint(1)', false, '0'))
    ->ensureColumn(new rex_sql_column('prio', 'int(10)', false, '0'))
    ->ensureColumn(new rex_sql_column('playground', 'tinyint(1)', false, '1'))
    ->ensureColumn(new rex_sql_column('createdate', 'datetime', false, 'CURRENT_TIMESTAMP'))
    ->ensureColumn(new rex_sql_column('createuser', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('updatedate', 'datetime', false, 'CURRENT_TIMESTAMP'))
    ->ensureColumn(new rex_sql_column('updateuser', 'varchar(255)', false, ''))
    ->ensureIndex(new rex_sql_index('key_unique', ['key'], rex_sql_index::UNIQUE))
    ->ensureIndex(new rex_sql_index('provider', ['provider']))
    ->ensureIndex(new rex_sql_index('is_default', ['is_default']))
    ->ensureIndex(new rex_sql_index('prio', ['prio']))
    ->ensure();

// Translation cache table.
rex_sql_table::get(rex::getTable('vtrans'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('api', 'varchar(32)', true, null))
    ->ensureColumn(new rex_sql_column('connection', 'varchar(191)', true, null))
    ->ensureColumn(new rex_sql_column('key', 'varchar(191)', true, null))
    ->ensureColumn(new rex_sql_column('hash', 'varchar(32)', true, null))
    ->ensureColumn(new rex_sql_column('length', 'int(11)', true, null))
    ->ensureColumn(new rex_sql_column('payload_length', 'int(11)', true, null))
    ->ensureColumn(new rex_sql_column('source', 'varchar(8)', true, null))
    ->ensureColumn(new rex_sql_column('target', 'varchar(8)', true, null))
    ->ensureColumn(new rex_sql_column('format', "ENUM('text', 'html')", false))
    ->ensureColumn(new rex_sql_column('text', 'mediumtext', true, null))
    ->ensureColumn(new rex_sql_column('prompt', 'text', true, null))
    ->ensureColumn(new rex_sql_column('custom_instructions', 'text', true, null))
    ->ensureColumn(new rex_sql_column('translation', 'mediumtext', true, null))
    ->ensureColumn(new rex_sql_column('duration_ms', 'int(11)', true, null))
    ->ensureColumn(new rex_sql_column('data', 'text'))
    ->ensureColumn(new rex_sql_column('createdate', 'datetime', false, 'CURRENT_TIMESTAMP'))
    ->ensureColumn(new rex_sql_column('createuser', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('updatedate', 'datetime', false, 'CURRENT_TIMESTAMP'))
    ->ensureColumn(new rex_sql_column('updateuser', 'varchar(255)', true))
    ->ensureIndex(new rex_sql_index('api', ['api']))
    ->ensureIndex(new rex_sql_index('connection', ['connection']))
    ->ensureIndex(new rex_sql_index('key', ['key']))
    ->ensureIndex(new rex_sql_index('hash', ['hash']))
    ->ensureIndex(new rex_sql_index('source', ['source']))
    ->ensureIndex(new rex_sql_index('target', ['target']))
    ->ensureIndex(new rex_sql_index('format', ['format']))
    // A stable key is one record per source language, target language, connection and
    // format. Without format the same key returned HTML markup in a text context and
    // vice versa.
    ->ensureIndex(new rex_sql_index('key_target_conn_src_format_unique', ['key', 'target', 'connection', 'source', 'format'], rex_sql_index::UNIQUE))
    ->ensure();

// Ensure addon data directory exists for storing credentials or other files.
$dataDir = rex_path::addonData('vtrans');
if (!is_dir($dataDir)) {
    rex_dir::create($dataDir);
}
