<?php

/** @var rex_addon $this */

// Migrate: rename column `active` → `is_default` for existing installations.
// Note: `default` is a MySQL reserved keyword, so `is_default` is used instead.
try {
    $migSql = rex_sql::factory();
    if (!empty($migSql->getArray('SHOW COLUMNS FROM ' . rex::getTable('vtrans_agent') . ' LIKE \'active\''))) {
        $migSql->setQuery('ALTER TABLE ' . rex::getTable('vtrans_agent') . ' CHANGE `active` `is_default` tinyint(1) NOT NULL DEFAULT 0');
        $migSql->setQuery('UPDATE ' . rex::getTable('vtrans_agent') . ' SET is_default = 0');
        $migSql->setQuery('UPDATE ' . rex::getTable('vtrans_agent') . ' SET is_default = 1 ORDER BY prio ASC, id ASC LIMIT 1');
    }
} catch (Throwable $e) {
    // Migration may already be done or the table is being created for the first time.
}

// Migrate: rename table vtrans_agent → vtrans_connection.
try {
    $migSql = rex_sql::factory();
    $tables = array_column($migSql->getArray('SHOW TABLES LIKE \'' . rex::getTablePrefix() . 'vtrans_agent\''), 'Tables_in_' . rex::getTablePrefix() . 'vtrans_agent');
    if (!empty($migSql->getArray('SHOW TABLES LIKE \'' . rex::getTablePrefix() . 'vtrans_agent\''))) {
        $migSql->setQuery('RENAME TABLE ' . rex::getTable('vtrans_agent') . ' TO ' . rex::getTable('vtrans_connection'));
    }
} catch (Throwable $e) {
    // Migration may already be done or the table is being created for the first time.
}

// Migrate: rename column `agent` → `connection` in vtrans table.
try {
    $migSql = rex_sql::factory();
    if (!empty($migSql->getArray('SHOW COLUMNS FROM ' . rex::getTable('vtrans') . ' LIKE \'agent\''))) {
        $migSql->setQuery('ALTER TABLE ' . rex::getTable('vtrans') . ' CHANGE `agent` `connection` varchar(191) NULL DEFAULT NULL');
        // Rename index key_target_agent_unique → key_target_connection_unique if it exists.
        $indexes = $migSql->getArray('SHOW INDEX FROM ' . rex::getTable('vtrans') . ' WHERE Key_name = \'key_target_agent_unique\'');
        if (!empty($indexes)) {
            $migSql->setQuery('ALTER TABLE ' . rex::getTable('vtrans') . ' DROP INDEX `key_target_agent_unique`');
            $migSql->setQuery('ALTER TABLE ' . rex::getTable('vtrans') . ' ADD UNIQUE KEY `key_target_connection_unique` (`key`, `target`, `connection`)');
        }
    }
} catch (Throwable $e) {
    // Migration may already be done or the table is being created for the first time.
}

// Migrate: drop old key_unique index on rex_vtrans (was only on `key`) — replaced by key_target_connection_unique.
try {
    $migSql = rex_sql::factory();
    $indexes = $migSql->getArray('SHOW INDEX FROM ' . rex::getTable('vtrans') . ' WHERE Key_name = \'key_unique\'');
    if (!empty($indexes)) {
        $migSql->setQuery('ALTER TABLE ' . rex::getTable('vtrans') . ' DROP INDEX `key_unique`');
    }
} catch (Throwable $e) {
    // Migration may already be done or the table is being created for the first time.
}

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
    ->ensureColumn(new rex_sql_column('text', 'text', true, null))
    ->ensureColumn(new rex_sql_column('prompt', 'text', true, null))
    ->ensureColumn(new rex_sql_column('custom_instructions', 'text', true, null))
    ->ensureColumn(new rex_sql_column('translation', 'text', true, null))
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
    ->ensureIndex(new rex_sql_index('key_target_connection_unique', ['key', 'target', 'connection'], rex_sql_index::UNIQUE))
    ->ensure();

// Ensure addon data directory exists for storing credentials or other files.
$dataDir = rex_path::addonData('vtrans');
if (!is_dir($dataDir)) {
    rex_dir::create($dataDir);
}

// Initialize default model configuration for the first installation.
$config = $this->getConfig();
$models = $config['models'] ?? [];
if (!is_array($models)) {
    $models = [];
}

if (!isset($models['myMemory']) || !is_array($models['myMemory'])) {
    $defaultConfig = $this->getProperty('default_config');
    $defaultMyMemory = is_array($defaultConfig)
        && isset($defaultConfig['models'])
        && is_array($defaultConfig['models'])
        && isset($defaultConfig['models']['myMemory'])
        && is_array($defaultConfig['models']['myMemory'])
        ? $defaultConfig['models']['myMemory']
        : [
            'api' => 'mymemory-v2',
            'label' => 'MyMemory',
            'apiUrl' => 'https://api.mymemory.translated.net/get',
            'apiKey' => '',
            'email' => '',
            'debug' => false,
            'timeout' => 30,
        ];

    $models['myMemory'] = $defaultMyMemory;
    $config['models'] = $models;
    $this->setConfig($config);
}

