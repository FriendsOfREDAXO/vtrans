<?php

/** @var rex_addon $this */

// Embedded mode is used when data details are shown inside testing results.
$isEmbedded = defined('VTRANS_DATA_EMBED') && true === VTRANS_DATA_EMBED;

$table = rex::getTable('vtrans');
$func = rex_get('func', 'string');
$id = rex_get('id', 'int');

$normalizeString = static function (mixed $value): string {
    if (is_string($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value) || is_bool($value)) {
        return (string) $value;
    }

    return '';
};
$normalizeInt = static function (mixed $value, int $default = 0): int {
    if (is_int($value)) {
        return $value;
    }

    if (is_float($value)) {
        return (int) $value;
    }

    if (is_string($value) && is_numeric($value)) {
        return (int) $value;
    }

    return $default;
};

$truncate = static function (?string $value, int $length = 80): string {
    $value = trim((string) $value);
    if ('' === $value) {
        return '';
    }

    if (mb_strlen($value) <= $length) {
        return $value;
    }

    return mb_substr($value, 0, $length) . '...';
};

$formatDuration = static function (mixed $durationMs) use ($normalizeInt, $normalizeString): string {
    if (null === $durationMs || '' === $normalizeString($durationMs)) {
        return '';
    }

    $durationInt = $normalizeInt($durationMs, 0);
    return number_format($durationInt / 1000, 2, '.', '') . 's';
};

$buildEditUrl = static function (int $entryId): string {
    return rex_url::currentBackendPage(['func' => 'edit', 'id' => $entryId]);
};

$buildDeleteUrl = static function (string $deleteBy, string $deleteValue): string {
    return rex_url::currentBackendPage([
        'func' => 'delete',
        'delete_by' => $deleteBy,
        'delete_value' => $deleteValue,
    ]);
};

$buildPlaygroundUrl = static function (array $params = []): string {
    return rex_url::backendPage('vtrans/playground', $params);
};

$normalizeDisplayData = null;
$normalizeDisplayData = static function (mixed $value) use (&$normalizeDisplayData): mixed {
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[is_string($key) ? $key : (string) $key] = $normalizeDisplayData($item);
        }
        return $normalized;
    }

    if (is_object($value)) {
        return null;
    }

    return $value;
};

$isAdmin = null !== rex::getUser() && rex::getUser()->isAdmin();

if ('delete' === $func) {
    if (!$isAdmin) {
        rex_response::sendRedirect(rex_url::currentBackendPage());
    }
    $deleteBy = rex_get('delete_by', 'string', 'id');
    $deleteValue = trim(rex_get('delete_value', 'string', ''));

    if (!in_array($deleteBy, ['id', 'key', 'hash'], true)) {
        $deleteBy = 'id';
    }

    if ('' === $deleteValue) {
        rex_response::sendRedirect(rex_url::currentBackendPage(['delete_state' => 'invalid']));
    }

    $whereSql = '';
    $params = [];

    if ('id' === $deleteBy) {
        $deleteId = (int) $deleteValue;
        if ($deleteId <= 0) {
            rex_response::sendRedirect(rex_url::currentBackendPage(['delete_state' => 'invalid']));
        }
        $whereSql = 'id = ?';
        $params = [$deleteId];
    } elseif ('key' === $deleteBy) {
        $whereSql = '`key` = ?';
        $params = [$deleteValue];
    } else {
        $whereSql = 'hash = ?';
        $params = [$deleteValue];
    }

    $countSql = rex_sql::factory();
    $countSql->setQuery('SELECT COUNT(*) AS cnt FROM ' . $table . ' WHERE ' . $whereSql, $params);
    $count = (int) $countSql->getValue('cnt');

    if ($count <= 0) {
        rex_response::sendRedirect(rex_url::currentBackendPage(['delete_state' => 'none']));
    }

    rex_sql::factory()->setQuery('DELETE FROM ' . $table . ' WHERE ' . $whereSql, $params);
    rex_response::sendRedirect(rex_url::currentBackendPage([
        'delete_state' => 'ok',
        'delete_count' => $count,
    ]));
}

$deleteState = rex_get('delete_state', 'string', '');
if ('ok' === $deleteState) {
    $deleteCount = max(1, rex_get('delete_count', 'int', 1));
    echo rex_view::success($this->i18n('vtrans_data_delete_success') . ': ' . $deleteCount);
} elseif ('none' === $deleteState) {
    echo rex_view::warning($this->i18n('vtrans_data_delete_no_match'));
} elseif ('invalid' === $deleteState) {
    echo rex_view::error($this->i18n('vtrans_data_delete_invalid'));
}

// Detail/edit mode for a single translation entry.
if ('edit' === $func && $id > 0) {
    $sql = rex_sql::factory();
    $sql->setQuery('SELECT * FROM ' . $table . ' WHERE id = ? LIMIT 1', [$id]);

    if (0 === $sql->getRows()) {
        echo rex_view::error($this->i18n('vtrans_data_not_found'));
        if ($isEmbedded) {
            return;
        }
        $func = '';
    } else {
        if (rex_post('data-save', 'boolean')) {
            $translation = rex_post('translation', 'string', '');

            $updateSql = rex_sql::factory();
            $updateSql->setTable($table);
            $updateSql->setWhere(['id' => $id]);
            $updateSql->setValue('translation', $translation);
            $updateSql->setValue('updatedate', date(rex_sql::FORMAT_DATETIME));
            $updateSql->setValue('updateuser', rex::getUser() ? rex::getUser()->getLogin() : 'system');
            $updateSql->update();

            rex_response::sendRedirect(rex_url::currentBackendPage(['func' => 'edit', 'id' => $id, 'saved' => 1]));
        }

        if (1 === rex_get('saved', 'int')) {
            echo rex_view::success($this->i18n('vtrans_data_saved'));
        }

        $row = [];
        foreach ($sql->getFieldnames() as $fieldName) {
            $row[$fieldName] = $sql->getValue($fieldName);
        }

        $detailElements = [];

        $readonlyFields = [
            'id',
            'api',
            'connection',
            'key',
            'hash',
            'length',
            'payload_length',
            'source',
            'target',
            'format',
            'duration_ms',
            'createdate',
            'createuser',
            'updatedate',
            'updateuser',
        ];

        foreach ($readonlyFields as $fieldName) {
            $value = $row[$fieldName] ?? '';
            if ('duration_ms' === $fieldName) {
                $value = $formatDuration($value);
            }

            $n = [];
            $n['label'] = '<label>' . $this->i18n('vtrans_field_' . $fieldName) . '</label>';
            $n['field'] = '<p class="form-control-static">' . nl2br(rex_escape((string) $value)) . '</p>';
            $detailElements[] = $n;
        }

        $n = [];
        $n['label'] = '<label for="rex-form-data-text">' . $this->i18n('vtrans_text') . '</label>';
        $n['field'] = '<textarea class="form-control" rows="8" id="rex-form-data-text" readonly>' . rex_escape((string) ($row['text'] ?? '')) . '</textarea>';
        $detailElements[] = $n;

        $n = [];
        $n['label'] = '<label for="rex-form-data-prompt">' . $this->i18n('vtrans_context') . '</label>';
        $n['field'] = '<textarea class="form-control" rows="5" id="rex-form-data-prompt" readonly>' . rex_escape((string) ($row['prompt'] ?? '')) . '</textarea>';
        $detailElements[] = $n;

        $n = [];
        $n['label'] = '<label for="rex-form-data-custom-instructions">' . $this->i18n('vtrans_custom_instructions') . '</label>';
        $customInstructionsRaw = (string) ($row['custom_instructions'] ?? '');
        $decodedCustomInstructions = json_decode($customInstructionsRaw, true);
        if (is_array($decodedCustomInstructions)) {
            $customInstructionsDisplay = implode("\n", array_filter(array_map(static function (mixed $line): string {
                return is_scalar($line) ? (string) $line : '';
            }, $decodedCustomInstructions), static function (string $line): bool {
                return '' !== trim($line);
            }));
        } else {
            $customInstructionsDisplay = $customInstructionsRaw;
        }
        $n['field'] = '<textarea class="form-control" rows="5" id="rex-form-data-custom-instructions" readonly>' . rex_escape($customInstructionsDisplay) . '</textarea>';
        $detailElements[] = $n;

        $n = [];
        $n['label'] = '<label for="rex-form-data-translation">' . $this->i18n('vtrans_field_translation') . '</label>';
        $n['field'] = '<textarea class="form-control" rows="8" id="rex-form-data-translation" name="translation">' . rex_escape((string) ($row['translation'] ?? '')) . '</textarea>';
        $detailElements[] = $n;

        $n = [];
        $n['label'] = '<label for="rex-form-data-data">' . $this->i18n('vtrans_field_data') . '</label>';
        $rawData = (string) ($row['data'] ?? '');
        $decodedData = json_decode($rawData, true);
        if (is_array($decodedData)) {
            $displayData = (string) json_encode($normalizeDisplayData($decodedData), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $displayData = $rawData;
        }
        $n['field'] = '<pre class="form-control-static" id="rex-form-data-data" style="max-height:420px; overflow:auto; border:1px solid #ddd; border-radius:4px; padding:10px; background:#f8f8f8; white-space:pre-wrap; word-break:break-word;">' . rex_escape($displayData) . '</pre>';
        $detailElements[] = $n;

        $fragment = new rex_fragment();
        $fragment->setVar('elements', $detailElements, false);
        $content = $fragment->parse('core/form/form.php');

        $buttonElements = [];
        $n = [];
        $n['field'] = '<button class="btn btn-save rex-form-aligned" type="submit" name="data-save" value="1">' . $this->i18n('vtrans_save') . '</button>';
        $buttonElements[] = $n;

        if ($isAdmin) {
            $deleteByIdUrl = $buildDeleteUrl('id', (string) $id);
            $n = [];
            $n['field'] = '<a class="btn btn-delete" href="' . $deleteByIdUrl . '" onclick="return confirm(\'' . rex_escape($this->i18n('vtrans_data_delete_confirm')) . '\');">' . $this->i18n('vtrans_data_delete') . '</a>';
            $buttonElements[] = $n;
        }

        $n = [];
        $n['field'] = '<a class="btn btn-default pull-right" href="' . $buildPlaygroundUrl(['retry_id' => $id]) . '"><i class="rex-icon fa-random"></i> ' . $this->i18n('vtrans_data_retry') . '</a>';
        $buttonElements[] = $n;

        $fragment = new rex_fragment();
        $fragment->setVar('flush', true);
        $fragment->setVar('elements', $buttonElements, false);
        $buttons = $fragment->parse('core/form/submit.php');

        $fragment = new rex_fragment();
        $fragment->setVar('class', 'edit');
        $fragment->setVar('title', $this->i18n('vtrans_data_detail_title') . ' #' . $id);
        $fragment->setVar('options', '<a class="btn btn-default btn-xs" href="' . rex_url::currentBackendPage() . '" title="' . rex_escape($this->i18n('vtrans_data_back_to_list')) . '"><i class="rex-icon fa-times"></i></a>', false);
        $fragment->setVar('body', $content, false);
        $fragment->setVar('buttons', $buttons, false);
        $content = $fragment->parse('core/page/section.php');

        echo '<form action="' . rex_url::currentBackendPage(['func' => 'edit', 'id' => $id]) . '" method="post">' . $content . '</form>';
        return;
    }
}

$search = rex_request('search', 'string', '');
$filterConnection = rex_request('filter_connection', 'string', '');
$filterApi = rex_request('filter_api', 'string', '');
$filterFormat = rex_request('filter_format', 'string', '');
$filterSource = rex_request('filter_source', 'string', '');
$filterTarget = rex_request('filter_target', 'string', '');
$filterPeriod = rex_request('filter_period', 'string', '');
$clearFilter = rex_request('clear_filter', 'string', '');

$monthNames = [];
for ($m = 1; $m <= 12; ++$m) {
    $monthNames[$m] = $this->i18n('vtrans_month_' . $m);
}

$periodOptions = [
    '' => $this->i18n('vtrans_data_period_all'),
    'today' => $this->i18n('vtrans_data_period_today'),
    'yesterday' => $this->i18n('vtrans_data_period_yesterday'),
    'this_week' => $this->i18n('vtrans_data_period_this_week'),
    'this_month' => $this->i18n('vtrans_data_period_this_month'),
    'this_year' => $this->i18n('vtrans_data_period_this_year'),
];

$last12MonthsSql = rex_sql::factory();
$last12MonthsSql->setQuery(
    'SELECT DATE_FORMAT(createdate, "%Y-%m") AS ym, COUNT(*) AS cnt FROM ' . $table . ' WHERE createdate >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), "%Y-%m-01") GROUP BY ym ORDER BY ym DESC'
);

for ($i = 0; $i < $last12MonthsSql->getRows(); ++$i) {
    $ym = trim((string) $last12MonthsSql->getValue('ym'));
    if (preg_match('/^(\d{4})-(\d{2})$/', $ym, $matches)) {
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $monthLabel = $monthNames[$month] ?? $ym;
        $periodOptions['month:' . $ym] = $monthLabel . ' ' . $year;
    }
    $last12MonthsSql->next();
}

if ('' !== $clearFilter) {
    $search = '';
    $filterConnection = '';
    $filterApi = '';
    $filterFormat = '';
    $filterSource = '';
    $filterTarget = '';
    $filterPeriod = '';
}

$currentFilterParams = [];
if ('' !== $search) {
    $currentFilterParams['search'] = $search;
}
if ('' !== $filterConnection) {
    $currentFilterParams['filter_connection'] = $filterConnection;
}
if ('' !== $filterApi) {
    $currentFilterParams['filter_api'] = $filterApi;
}
if ('' !== $filterFormat) {
    $currentFilterParams['filter_format'] = $filterFormat;
}
if ('' !== $filterSource) {
    $currentFilterParams['filter_source'] = $filterSource;
}
if ('' !== $filterTarget) {
    $currentFilterParams['filter_target'] = $filterTarget;
}
if ('' !== $filterPeriod) {
    $currentFilterParams['filter_period'] = $filterPeriod;
}

$sqlEscaper = rex_sql::factory();
$whereParts = [];

if ('' !== trim($search)) {
    $escapedSearch = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $search);
    $searchParts = [
        '`key` LIKE ' . $sqlEscaper->escape('%' . $escapedSearch . '%'),
        '`text` LIKE ' . $sqlEscaper->escape('%' . $escapedSearch . '%'),
        'translation LIKE ' . $sqlEscaper->escape('%' . $escapedSearch . '%'),
    ];

    if (ctype_digit(trim($search))) {
        $searchParts[] = 'id = ' . (int) $search;
    }

    $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';
}

if ('' !== $filterConnection) {
    $whereParts[] = 'connection = ' . $sqlEscaper->escape($filterConnection);
}

if ('' !== $filterApi) {
    $whereParts[] = 'api = ' . $sqlEscaper->escape($filterApi);
}

if ('' !== $filterFormat) {
    $whereParts[] = '`format` = ' . $sqlEscaper->escape($filterFormat);
}

if ('' !== $filterSource) {
    $whereParts[] = 'source = ' . $sqlEscaper->escape($filterSource);
}

if ('' !== $filterTarget) {
    $whereParts[] = 'target = ' . $sqlEscaper->escape($filterTarget);
}

$periodStart = null;
$periodEnd = null;

if ('today' === $filterPeriod) {
    $periodStart = date('Y-m-d 00:00:00');
    $periodEnd = date('Y-m-d 00:00:00', strtotime('+1 day'));
} elseif ('yesterday' === $filterPeriod) {
    $periodStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
    $periodEnd = date('Y-m-d 00:00:00');
} elseif ('this_week' === $filterPeriod) {
    $weekday = (int) date('N');
    $periodStart = date('Y-m-d 00:00:00', strtotime('-' . ($weekday - 1) . ' days'));
    $periodEnd = date('Y-m-d 00:00:00', strtotime('+1 day'));
} elseif ('this_month' === $filterPeriod) {
    $periodStart = date('Y-m-01 00:00:00');
    $periodEnd = date('Y-m-d 00:00:00', strtotime('+1 day'));
} elseif ('this_year' === $filterPeriod) {
    $periodStart = date('Y-01-01 00:00:00');
    $periodEnd = date('Y-m-d 00:00:00', strtotime('+1 day'));
} elseif (preg_match('/^month:(\d{4})-(\d{2})$/', $filterPeriod, $matches)) {
    $year = (int) $matches[1];
    $month = (int) $matches[2];
    if ($year >= 2000 && $month >= 1 && $month <= 12) {
        $periodStart = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $periodEndTimestamp = strtotime($periodStart . ' +1 month');
        $periodEnd = false !== $periodEndTimestamp
            ? date('Y-m-d 00:00:00', $periodEndTimestamp)
            : null;
    }
}

if (null !== $periodStart && null !== $periodEnd) {
    $whereParts[] = 'createdate >= ' . $sqlEscaper->escape($periodStart) . ' AND createdate < ' . $sqlEscaper->escape($periodEnd);
}

$whereSql = [] !== $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';

if ($isAdmin && rex_post('data-delete-batch', 'boolean')) {
    $countSql = rex_sql::factory();
    $countSql->setQuery('SELECT COUNT(*) AS cnt FROM ' . $table . $whereSql);
    $count = (int) $countSql->getValue('cnt');

    if ($count <= 0) {
        rex_response::sendRedirect(rex_url::currentBackendPage(array_merge($currentFilterParams, ['delete_state' => 'none'])));
    }

    rex_sql::factory()->setQuery('DELETE FROM ' . $table . $whereSql);
    rex_response::sendRedirect(rex_url::currentBackendPage(array_merge($currentFilterParams, [
        'delete_state' => 'ok',
        'delete_count' => $count,
    ])));
}

$query = 'SELECT id, createdate, connection, api, CONCAT(COALESCE(connection, ""), "\n", COALESCE(api, "")) AS connection_api, `key`, `hash`, source, `target`, `format`, `length`, `text`, `payload_length`, `prompt`, custom_instructions, translation, `data`, duration_ms, createuser, updatedate, updateuser FROM ' . $table;
$query .= $whereSql;

$entryCountSql = rex_sql::factory();
$entryCountSql->setQuery('SELECT COUNT(*) AS cnt FROM ' . $table . $whereSql);
$entryCount = (int) $entryCountSql->getValue('cnt');

$lengthSumSql = rex_sql::factory();
$lengthSumSql->setQuery('SELECT COALESCE(SUM(`length`), 0) AS total_length FROM ' . $table . $whereSql);
$totalLength = (int) $lengthSumSql->getValue('total_length');

$payloadLengthSumSql = rex_sql::factory();
$payloadLengthSumSql->setQuery('SELECT COALESCE(SUM(`payload_length`), 0) AS total_payload_length FROM ' . $table . $whereSql);
$totalPayloadLength = (int) $payloadLengthSumSql->getValue('total_payload_length');

$connectionsResult = rex_sql::factory()->getArray('SELECT connection FROM ' . $table . ' WHERE connection IS NOT NULL AND connection != "" GROUP BY connection ORDER BY connection');
$apisResult = rex_sql::factory()->getArray('SELECT api FROM ' . $table . ' WHERE api IS NOT NULL AND api != "" GROUP BY api ORDER BY api');
$sourcesResult = rex_sql::factory()->getArray('SELECT source FROM ' . $table . ' WHERE source IS NOT NULL AND source != "" GROUP BY source ORDER BY source');
$targetsResult = rex_sql::factory()->getArray('SELECT target FROM ' . $table . ' WHERE target IS NOT NULL AND target != "" GROUP BY target ORDER BY target');

$connectionOptions = [];
foreach ($connectionsResult as $row) {
    $val = (string) ($row['connection'] ?? '');
    if ('' !== $val) {
        $connectionOptions[$val] = $val;
    }
}

$sourceOptions = [];
foreach ($sourcesResult as $row) {
    $val = (string) ($row['source'] ?? '');
    if ('' !== $val) {
        $sourceOptions[$val] = $val;
    }
}

$apiOptions = [];
foreach ($apisResult as $row) {
    $val = (string) ($row['api'] ?? '');
    if ('' !== $val) {
        $apiOptions[$val] = $val;
    }
}

$targetOptions = [];
foreach ($targetsResult as $row) {
    $val = (string) ($row['target'] ?? '');
    if ('' !== $val) {
        $targetOptions[$val] = $val;
    }
}

$formatOptions = [
    'text' => 'Text',
    'html' => 'HTML',
];

echo '<form action="' . rex_url::currentBackendPage() . '" class="panel panel-default" method="get" style="padding:10px;">';
echo '<input type="hidden" name="page" value="' . rex_escape(rex_get('page', 'string')) . '">';
echo '<div class="row" style="margin-bottom:10px;">';
echo '<div class="col-sm-12" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">';

echo '<div class="form-group" style="width:220px; margin-bottom:0;">';
echo '<label for="vtrans-filter-search">' . $this->i18n('vtrans_data_search') . '</label>';
echo '<input type="text" class="form-control" name="search" id="vtrans-filter-search" value="' . rex_escape($search) . '" placeholder="' . rex_escape($this->i18n('vtrans_data_search_placeholder')) . '">';
echo '</div>';

$connectionSelect = new rex_select();
$connectionSelect->setName('filter_connection');
$connectionSelect->setId('vtrans-filter-connection');
$connectionSelect->setAttribute('class', 'form-control selectpicker');
$connectionSelect->setAttribute('onchange', 'this.form.submit()');
$connectionSelect->setMultiple(false);
$connectionSelect->addOption('-', '');
$connectionSelect->addOptions($connectionOptions);
$connectionSelect->setSelected($filterConnection);

echo '<div class="form-group" style="width:160px; margin-bottom:0;">';
echo '<label for="vtrans-filter-connection">' . $this->i18n('vtrans_connection') . '</label>';
echo $connectionSelect->get();
echo '</div>';

$apiSelect = new rex_select();
$apiSelect->setName('filter_api');
$apiSelect->setId('vtrans-filter-api');
$apiSelect->setAttribute('class', 'form-control selectpicker');
$apiSelect->setAttribute('onchange', 'this.form.submit()');
$apiSelect->setMultiple(false);
$apiSelect->addOption('-', '');
$apiSelect->addOptions($apiOptions);
$apiSelect->setSelected($filterApi);

echo '<div class="form-group" style="width:160px; margin-bottom:0;">';
echo '<label for="vtrans-filter-api">' . $this->i18n('vtrans_data_api') . '</label>';
echo $apiSelect->get();
echo '</div>';

$formatSelect = new rex_select();
$formatSelect->setName('filter_format');
$formatSelect->setId('vtrans-filter-format');
$formatSelect->setAttribute('class', 'form-control selectpicker');
$formatSelect->setAttribute('onchange', 'this.form.submit()');
$formatSelect->setMultiple(false);
$formatSelect->addOption('-', '');
$formatSelect->addOptions($formatOptions);
$formatSelect->setSelected($filterFormat);

echo '<div class="form-group" style="width:130px; margin-bottom:0;">';
echo '<label for="vtrans-filter-format">' . $this->i18n('vtrans_format') . '</label>';
echo $formatSelect->get();
echo '</div>';

$sourceSelect = new rex_select();
$sourceSelect->setName('filter_source');
$sourceSelect->setId('vtrans-filter-source');
$sourceSelect->setAttribute('class', 'form-control selectpicker');
$sourceSelect->setAttribute('onchange', 'this.form.submit()');
$sourceSelect->setMultiple(false);
$sourceSelect->addOption('-', '');
$sourceSelect->addOptions($sourceOptions);
$sourceSelect->setSelected($filterSource);

echo '<div class="form-group" style="width:130px; margin-bottom:0;">';
echo '<label for="vtrans-filter-source">' . $this->i18n('vtrans_field_source') . '</label>';
echo $sourceSelect->get();
echo '</div>';

$targetSelect = new rex_select();
$targetSelect->setName('filter_target');
$targetSelect->setId('vtrans-filter-target');
$targetSelect->setAttribute('class', 'form-control selectpicker');
$targetSelect->setAttribute('onchange', 'this.form.submit()');
$targetSelect->setMultiple(false);
$targetSelect->addOption('-', '');
$targetSelect->addOptions($targetOptions);
$targetSelect->setSelected($filterTarget);

echo '<div class="form-group" style="width:130px; margin-bottom:0;">';
echo '<label for="vtrans-filter-target">' . $this->i18n('vtrans_field_target') . '</label>';
echo $targetSelect->get();
echo '</div>';

$periodSelect = new rex_select();
$periodSelect->setName('filter_period');
$periodSelect->setId('vtrans-filter-period');
$periodSelect->setAttribute('class', 'form-control selectpicker');
$periodSelect->setAttribute('onchange', 'this.form.submit()');
$periodSelect->setMultiple(false);
$periodSelect->addOptions($periodOptions);
$periodSelect->setSelected($filterPeriod);

echo '<div class="form-group" style="width:180px; margin-bottom:0;">';
echo '<label for="vtrans-filter-period">' . $this->i18n('vtrans_data_period') . '</label>';
echo $periodSelect->get();
echo '</div>';

echo '<div class="form-group" style="margin-bottom:0;">';
echo '<label>&nbsp;</label>';
echo '<div class="btn-group" style="display:flex; gap:6px; white-space:nowrap;">';
echo '<button type="submit" class="btn btn-default" name="filter" value="1">' . $this->i18n('vtrans_data_filter_apply') . '</button> ';
echo '<button type="submit" class="btn btn-danger" name="clear_filter" value="1"><i class="rex-icon fa-times"></i></button>';
echo '</div>';
echo '</div>';

echo '</div>';
echo '</div>';
echo '</form>';
echo '<hr style="margin-top:0; margin-bottom:0;">';

// Use rex_list for sortable/paginated table rendering in backend.
$rowsPerPage = $normalizeInt($this->getConfig('rows_per_page'), 100);
$list = rex_list::factory(
    $query,
    $rowsPerPage,
    'vtrans_data',
    false,
    1,
    ['createdate' => 'desc']
);

$list->setColumnLabel('id', $this->i18n('vtrans_field_id'));
$list->setColumnLabel('createdate', '<span title=\'' . rex_escape($this->i18n('vtrans_field_createdate')) . '\'><i class=\'fa fa-list\'></i></span>');
$list->setColumnLabel('connection_api', '<span title=\'' . rex_escape($this->i18n('vtrans_field_connection_api')) . '\'><i class=\'fa fa-plug\'></i></span>');
$list->setColumnLabel('key', $this->i18n('vtrans_field_key'));
$list->setColumnLabel('hash', $this->i18n('vtrans_field_hash'));
$list->setColumnLabel('source', '<span title=\'' . rex_escape($this->i18n('vtrans_field_source')) . '\'><i class=\'fa fa-arrow-circle-o-up\'></i></span>');
$list->setColumnLabel('target', '<span title=\'' . rex_escape($this->i18n('vtrans_field_target')) . '\'><i class=\'fa fa-arrow-circle-o-down\'></i></span>');
$list->setColumnLabel('format', '<span title=\'' . rex_escape($this->i18n('vtrans_field_settings')) . '\'><i class=\'fa fa-sliders\'></i></span>');
$list->setColumnLabel('length', '<span title=\'' . rex_escape($this->i18n('vtrans_field_chars')) . '\'><i class=\'fa fa-file-text-o\'></i></span> ' . rex_escape($this->i18n('vtrans_text')));
$list->setColumnLabel('payload_length', '<span title=\'' . rex_escape($this->i18n('vtrans_field_payload_length')) . '\'><i class=\'fa fa-paper-plane-o\'></i></span> ' . rex_escape($this->i18n('vtrans_result_title')));
$list->setColumnLabel('data', $this->i18n('vtrans_field_data'));
$list->setColumnLabel('duration_ms', '<span title=\'' . rex_escape($this->i18n('vtrans_field_duration_ms')) . '\'><i class=\'fa fa-tachometer\'></i></span>');
$list->setColumnLabel('createuser', $this->i18n('vtrans_field_createuser'));
$list->setColumnLabel('updatedate', $this->i18n('vtrans_field_updatedate'));
$list->setColumnLabel('updateuser', $this->i18n('vtrans_field_updateuser'));

$list->removeColumn('id');
$list->removeColumn('hash');
$list->removeColumn('connection');
$list->removeColumn('api');
$list->removeColumn('text');
$list->removeColumn('translation');
$list->removeColumn('prompt');
$list->removeColumn('custom_instructions');
$list->removeColumn('data');
$list->removeColumn('createuser');
$list->removeColumn('updatedate');
$list->removeColumn('updateuser');

$list->setColumnFormat('updatedate', 'date', 'd.m.Y H:i:s');

$list->setColumnSortable('id');
$list->setColumnSortable('length');
$list->setColumnSortable('payload_length');
$list->setColumnSortable('createdate');
$list->setColumnSortable('duration_ms');

if ('' !== $search) {
    $list->addParam('search', $search);
}
if ('' !== $filterConnection) {
    $list->addParam('filter_connection', $filterConnection);
}
if ('' !== $filterApi) {
    $list->addParam('filter_api', $filterApi);
}
if ('' !== $filterFormat) {
    $list->addParam('filter_format', $filterFormat);
}
if ('' !== $filterSource) {
    $list->addParam('filter_source', $filterSource);
}
if ('' !== $filterTarget) {
    $list->addParam('filter_target', $filterTarget);
}
if ('' !== $filterPeriod) {
    $list->addParam('filter_period', $filterPeriod);
}

$list->setColumnLayout('key', ['<th>###VALUE###</th>', '<td>###VALUE###</td>']);
$list->setColumnLayout('length', ['<th style="width:400px">###VALUE###</th>', '<td>###VALUE###</td>']);
$list->setColumnLayout('payload_length', ['<th style="width:400px">###VALUE###</th>', '<td>###VALUE###</td>']);



$list->setColumnFormat('length', 'custom', function () use ($list, $truncate) {
    $rawLength = $list->getValue('length');
    $length = is_numeric((string) $rawLength) ? (float) $rawLength : 0.0;
    $text = (string)  $list->getValue('text');
    if ('html' === (string) $list->getValue('format')) {
        $text = strip_tags($text, '<h1><h2><h3><h4><h5><h6><p><br>');
    }
    $text = trim((string) preg_replace('/[\t\s\r\n]+/', ' ', $text));
    return '<span title="' . rex_escape($this->i18n('vtrans_chars')) . '"><small>[' . number_format($length, 0, ',', '.') . ']</small></span><div class="vtrans-tcont" title="' . rex_escape($truncate($text, 800)) . '">' . rex_escape($truncate($text, 200)) . '</div>';
});

$list->setColumnFormat('payload_length', 'custom', function () use ($list, $truncate, $normalizeString) {
    $rawLength = $list->getValue('length');
    $rawPayloadLength = $list->getValue('payload_length');
    $tLength = is_numeric((string) $rawLength) ? (float) $rawLength : 0.0;
    $pLength = is_numeric((string) $rawPayloadLength) ? (float) $rawPayloadLength : 0.0;
    $percReduction = $tLength > 0.0 ? (1 - ($pLength / $tLength)) * 100 : 0.0;
    $htmlText = (string)  $list->getValue('translation');
    if (!$htmlText) {
        $dataString = (string) $list->getValue('data');
        $data = '' !== $dataString ? json_decode($dataString, true) : null;
        $status = is_array($data) ? $normalizeString($data['status'] ?? '') : '';
        $error = is_array($data) ? $normalizeString($data['error'] ?? '') : '';
        $htmlText = '<div class="vtrans-tcont text-warning" style="opacity:1"><i class="fa fa-warning text-danger"></i> ' . strtoupper($status) . ': ' . rex_escape($truncate($error, 100)) . '</div>';
    } else {
        if ('html' === (string) $list->getValue('format')) {
            $htmlText = strip_tags($htmlText, '<h1><h2><h3><h4><h5><h6><p><br>');
        }
        $htmlText = trim((string) preg_replace('/[\t\s\r\n]+/', ' ', $htmlText));
        $htmlText = '<div class="vtrans-tcont" title="' . rex_escape($truncate($htmlText, 800)) . '">' . rex_escape($truncate($htmlText, 200)) . '</div>';
    }
    $reductionHtml = $percReduction > 0.0 ? ' <small>(-' . number_format($percReduction, 1, ',', '.') . '%)</small>' : '';
    return '<span title="' . rex_escape($this->i18n('vtrans_field_payload_length')) . '"><small>[' . number_format($pLength, 0, ',', '.') . ']</small> ' . $reductionHtml . '</span>' . $htmlText;
});

$list->setColumnFormat('createdate', 'custom', static function () use ($list, $buildEditUrl) {
    $entryId = (int) $list->getValue('id');
    $createdAt = (string) $list->getValue('createdate');
    $timestamp = '' !== $createdAt ? strtotime($createdAt) : false;
    if (false === $timestamp) {
        return '<a href="' . $buildEditUrl($entryId) . '">' . rex_escape($createdAt) . '</a>';
    }
    return '<a href="' . $buildEditUrl($entryId) . '">' . date('d.m.Y H:i:s', $timestamp) . '</a>';
});

$list->setColumnFormat('connection_api', 'custom', static function () use ($list) {
    $connection = trim((string) $list->getValue('connection'));
    $api = trim((string) $list->getValue('api'));

    $parts = [];
    if ('' !== $connection) {
        $parts[] = rex_escape($connection);
    }
    if ('' !== $api) {
        $parts[] = rex_escape($api);
    }

    return '' !== implode('', $parts) ? implode('<br>', $parts)  : '-';
});

$list->setColumnFormat('format', 'custom', static function () use ($list, $truncate) {
    $format = strtolower(trim((string) $list->getValue('format')));

    $promptText = trim((string) $list->getValue('prompt'));
    $customInstructionsRaw = trim((string) $list->getValue('custom_instructions'));
    $customInstructionsText = $customInstructionsRaw;
    if ('' !== $customInstructionsRaw) {
        $decodedCustomInstructions = json_decode($customInstructionsRaw, true);
        if (is_array($decodedCustomInstructions)) {
            $customInstructionsText = implode("\n", array_filter(
                array_map(
                    static function (mixed $line): string {
                        return is_scalar($line) ? (string) $line : '';
                    },
                    $decodedCustomInstructions
                ),
                static function (string $line): bool {
                    return '' !== trim($line);
                }
            ));
        }
    }

    $isText = 'text' === $format;
    $isHtml = 'html' === $format;
    $hasPrompt = '' !== $promptText;
    $hasCustomInstructions = '' !== trim($customInstructionsText);

    $iconStyle = static function (bool $active): string {
        return $active ? 'opacity:1;' : 'opacity:.25; color:#666;';
    };

    $formatIconClass = $isHtml ? 'fa-code' : 'fa-file-text-o';
    $formatTitle = $isHtml ? 'HTML' : 'Text';
    $promptTitle = $hasPrompt ? $truncate($promptText, 140) : '-';
    $customInstructionsTitle = $hasCustomInstructions ? $truncate($customInstructionsText, 140) : '-';

    return '<span title="' . rex_escape($formatTitle) . '" style="display:inline-block; margin-right:6px;"><i class="fa ' . $formatIconClass . '" style="' . $iconStyle(true) . '"></i></span>'
        . '<span title="' . rex_escape($promptTitle) . '" style="display:inline-block; margin-right:6px;"><i class="fa fa-commenting-o" style="' . $iconStyle($hasPrompt) . '"></i></span>'
        . '<span title="' . rex_escape($customInstructionsTitle) . '" style="display:inline-block;"><i class="fa fa-list-ul" style="' . $iconStyle($hasCustomInstructions) . '"></i></span>';
});



$list->setColumnFormat('duration_ms', 'custom', static function () use ($list, $formatDuration) {
    $ms = (int) $list->getValue('duration_ms');
    $label = $formatDuration($ms);
    $payloadLength = max(1, (int) $list->getValue('payload_length'));
    $overhead = 1000; // base roundtrip overhead in ms
    $msPerChar = max(0, $ms - $overhead) / $payloadLength;
    if ($msPerChar <= 1.0) {
        $color = '#2e7d32'; // green – up to 1 ms/char
    } elseif ($msPerChar <= 4.0) {
        $color = '#e65100'; // orange – up to 4 ms/char
    } else {
        $color = '#c62828'; // red – above 4 ms/char
    }
    return '<span style="color:' . $color . '; font-weight:600">' . $label . '</span>';
});

$deleteConfirmText = rex_escape($this->i18n('vtrans_data_delete_confirm'));

$batchDeleteConfirmText = rex_escape($this->i18n('vtrans_data_delete_batch_confirm'));



if ($isAdmin) {
    $list->addColumn('data_delete', '<i class="rex-icon rex-icon-delete"></i>', -1, ['<th>###VALUE###</th>', '<td>###VALUE###</td>']);
    $list->setColumnLabel('data_delete', '');
    $list->setColumnFormat('data_delete', 'custom', static function () use ($list, $buildDeleteUrl, $deleteConfirmText) {
        $entryId = (int) $list->getValue('id');
        $deleteUrl = $buildDeleteUrl('id', (string) $entryId);
        return '<a href="' . $deleteUrl . '" onclick="return confirm(\'' . $deleteConfirmText . '\');" ><i class="rex-icon rex-icon-delete"></i></a>';
    });
}

$content = $list->get();

$headerOptions = '<span class="btn-xs" style="margin-right:8px; padding:0">' . $this->i18n('vtrans_chars') . ': ' . number_format($totalLength, 0, ',', '.') . '</span>';
$headerOptions .= '<span class="btn-xs" style="margin-right:8px; padding:0"><i class="fa fa-paper-plane-o"></i> ' . $this->i18n('vtrans_field_payload_length') . ': ' . number_format($totalPayloadLength, 0, ',', '.') . '</span>';
if ($isAdmin && $entryCount > 0) {
	$batchDeleteLabelKey = 1 === $entryCount ? 'vtrans_data_delete_single' : 'vtrans_data_delete_multiple';
	$batchDeleteLabel = str_replace('%s', (string) $entryCount, $this->i18n($batchDeleteLabelKey));

    $headerOptions .= '<form action="' . rex_url::currentBackendPage() . '" method="post" style="display:inline-block;margin:0">';
    foreach ($currentFilterParams as $paramKey => $paramValue) {
        $headerOptions .= '<input type="hidden" name="' . rex_escape($paramKey) . '" value="' . rex_escape((string) $paramValue) . '">';
    }
    $headerOptions .= '<button type="submit" class="btn btn-danger btn-xs" name="data-delete-batch" value="1" onclick="return confirm(\'' . $batchDeleteConfirmText . '\');">' . rex_escape($batchDeleteLabel) . '</button>';
    $headerOptions .= '</form>';
}

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('vtrans_data_title'));
$fragment->setVar('options', $headerOptions, false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');

?>
<style>
    .vtrans-tcont {
        padding-top:.3em;
        font-size:60%;
        opacity:0.6;
    }

<?php

