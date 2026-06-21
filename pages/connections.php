<?php

/** @var rex_addon $this */

use FriendsOfRedaxo\VTrans\VTrans;
use FriendsOfRedaxo\VTrans\VTransConnection;

$func = rex_request('func', 'string', '');
$id = rex_request('id', 'int', 0);
$messages = [];

// Available providers for the select dropdown.
$availableProviders = VTrans::getAvailableProviders();
$providerOptions = [];
foreach ($availableProviders as $api => $provider) {
    $providerOptions[$api] = $provider->getProviderLabel() . ' (' . $api . ')';
}

// Handle set default.
if ('set_default' === $func && $id > 0) {
    if (null !== VTransConnection::getById($id)) {
        VTransConnection::setAsDefault($id);
    }
    $func = '';
    $id = 0;
}

// Handle toggle playground.
if ('toggle_playground' === $func && $id > 0) {
    $connection = VTransConnection::getById($id);
    if (null !== $connection) {
        $connection->setPlayground(!$connection->isPlayground());
        $connection->save();
    }
    $func = '';
    $id = 0;
}

// Handle move up.
if ('move_up' === $func && $id > 0) {
    VTransConnection::moveUp($id);
    $func = '';
    $id = 0;
}

// Handle move down.
if ('move_down' === $func && $id > 0) {
    VTransConnection::moveDown($id);
    $func = '';
    $id = 0;
}

// Handle delete.
if ('delete' === $func && $id > 0) {
    $connection = VTransConnection::getById($id);
    if (null !== $connection) {
        $connection->delete();
        $messages[] = rex_view::success($this->i18n('vtrans_connections_deleted'));
    }
    $func = '';
    $id = 0;
}

// Handle save.
if (rex_post('connection-submit', 'boolean') || rex_post('connection-apply', 'boolean')) {
    $postProvider = rex_post('provider', 'string', '');
    $postKey = rex_post('connection_key', 'string', '');
    $postLabel = rex_post('label', 'string', '');
    $postPlayground = rex_post('playground', 'int', 1);
    $postDebug = rex_post('debug', 'int', 0);

    // Common column fields.
    $postApiKey = rex_post('api_key', 'string', '');
    $postApiUrl = rex_post('api_url', 'string', '');
    $postSystemPrompt = rex_post('system_prompt', 'string', '');
    $postTimeout = rex_post('timeout', 'int', 30);
    $postMaxCharsRaw = rex_post('max_chars', 'string', '');
    $postMaxChars = '' !== trim($postMaxCharsRaw) && (int) $postMaxCharsRaw > 0 ? (int) $postMaxCharsRaw : null;

    $errors = [];
    $saveAndStay = rex_post('connection-apply', 'boolean');

    // Validate key.
    $keyError = VTransConnection::validateKey($postKey);
    if (null !== $keyError) {
        $errors['key'] = $keyError;
    } elseif (VTransConnection::keyExists($postKey, $id > 0 ? $id : null)) {
        $errors['key'] = $this->i18n('vtrans_connections_key_exists');
    }

    if ('' === trim($postLabel)) {
        $errors['label'] = $this->i18n('vtrans_connections_label_required');
    }

    if ('' === $postProvider || !isset($availableProviders[$postProvider])) {
        $errors['provider'] = $this->i18n('vtrans_connections_provider_required');
    }

    // Provider-specific validation.
    $providerInstance = isset($availableProviders[$postProvider]) ? $availableProviders[$postProvider] : null;
    $configFields = null !== $providerInstance ? $providerInstance->getConfigFields() : [];
    $params = [];

    if (null !== $providerInstance) {
        // Collect field values for validation.
        $fieldValues = [
            'api_key' => $postApiKey,
            'api_url' => $postApiUrl,
            'system_prompt' => $postSystemPrompt,
            'timeout' => $postTimeout,
        ];

        foreach ($configFields as $fieldName => $fieldDef) {
            $isColumn = !empty($fieldDef['column']);
            if (!$isColumn) {
                $fieldValues[$fieldName] = rex_post($fieldName, 'string', '');
            }
        }

        $providerErrors = $providerInstance->validateConfig($fieldValues);
        $errors = array_merge($errors, $providerErrors);

        // Collect params (non-column fields).
        foreach ($configFields as $fieldName => $fieldDef) {
            $isColumn = !empty($fieldDef['column']);
            if (!$isColumn) {
                $value = rex_post($fieldName, 'string', '');
                if ('' !== trim($value)) {
                    $params[$fieldName] = $value;
                }
            }
        }
    }

    if ([] === $errors) {
        $connection = $id > 0 ? VTransConnection::getById($id) : null;
        if (null === $connection) {
            $connection = new VTransConnection();
        }

        $connection->setKey($postKey);
        $connection->setLabel($postLabel);
        $connection->setProvider($postProvider);
        $connection->setApiKey('' !== $postApiKey ? $postApiKey : null);
        $connection->setApiUrl($postApiUrl);
        $connection->setSystemPrompt('' !== trim($postSystemPrompt) ? $postSystemPrompt : null);
        $connection->setTimeout($postTimeout);
        $connection->setMaxChars($postMaxChars);
        $connection->setDebug((bool) $postDebug);
        $connection->setParams($params);
        if (0 === $connection->getId()) {
            $connection->setPrio(VTransConnection::getNextPrio());
        }
        $connection->setPlayground((bool) $postPlayground);
        $connection->save();

        $messages[] = rex_view::success($this->i18n('vtrans_connections_saved'));
        if ($saveAndStay && $connection->getId() > 0) {
            $func = 'edit';
            $id = $connection->getId();
        } else {
            $func = '';
            $id = 0;
        }
    } else {
        $messages[] = rex_view::error(implode('<br>', array_map('rex_escape', array_values($errors))));
        $func = $id > 0 ? 'edit' : 'add';
    }
}

// Show messages.
foreach ($messages as $msg) {
    echo $msg;
}

// --- Add / Edit form ---
if ('add' === $func || ('edit' === $func && $id > 0)) {
    $connection = null;
    $formTitle = $this->i18n('vtrans_connections_add');

    if ('edit' === $func && $id > 0) {
        $connection = VTransConnection::getById($id);
        if (null === $connection) {
            echo rex_view::error($this->i18n('vtrans_connections_not_found'));
            $func = '';
        } else {
            $formTitle = $this->i18n('vtrans_connections_edit') . ' — ' . rex_escape($connection->getLabel());
        }
    }

    if ('add' === $func || null !== $connection) {
        // Determine current values (from POST on error/reload, from DB on edit, or defaults on add).
        $isSubmit = rex_post('connection-submit', 'boolean');
        $isProviderReload = rex_post('_reload_for_provider', 'int', 0) === 1;
        $isFormPost = $isSubmit || $isProviderReload;
        $isEditMode = $id > 0;

        $currentProvider = $isFormPost ? rex_post('provider', 'string', '') : ($connection?->getProvider() ?? '');
        $currentKey = $isFormPost ? rex_post('connection_key', 'string', '') : ($connection?->getKey() ?? '');
        $currentLabel = $isFormPost ? rex_post('label', 'string', '') : ($connection?->getLabel() ?? '');
        $currentApiKey = $isFormPost ? rex_post('api_key', 'string', '') : ($connection?->getApiKey() ?? '');
        $currentApiUrl = $isFormPost ? rex_post('api_url', 'string', '') : ($connection?->getApiUrl() ?? '');
        $currentSystemPrompt = $isFormPost ? rex_post('system_prompt', 'string', '') : ($connection?->getSystemPrompt() ?? '');
        $currentTimeout = $isFormPost ? rex_post('timeout', 'int', 30) : ($connection?->getTimeout() ?? 30);
        $currentMaxCharsRaw = $isFormPost ? rex_post('max_chars', 'string', '') : (null !== $connection?->getMaxChars() ? (string) $connection->getMaxChars() : '');
        $currentDebug = $isFormPost ? rex_post('debug', 'int', 0) : (int) ($connection?->isDebug() ?? false);
        $currentPlayground = $isFormPost ? rex_post('playground', 'int', 1) : (int) ($connection?->isPlayground() ?? true);
        $currentParams = $connection?->getParams() ?? [];

        $formElements = [];

        // Provider field: locked in edit mode, dynamic-reload select in add mode.
        $n = [];
        $n['label'] = '<label for="vtrans-connection-provider">' . $this->i18n('vtrans_connections_provider') . ' *</label>';
        if ($isEditMode) {
            $n['field'] = '<p class="form-control-static">' . rex_escape($currentProvider) . '</p><input type="hidden" name="provider" value="' . rex_escape($currentProvider) . '">';
            $n['note'] = '<p class="help-block">' . $this->i18n('vtrans_connections_provider_locked') . '</p>';
        } else {
            $providerSelect = new rex_select();
            $providerSelect->setName('provider');
            $providerSelect->setId('vtrans-connection-provider');
            $providerSelect->setAttribute('class', 'form-control selectpicker');
            $providerSelect->setAttribute('onchange', 'document.getElementById(\'vtrans-provider-reload\').value=1;this.form.submit()');
            $providerSelect->addOption('— ' . $this->i18n('vtrans_connections_select_provider') . ' —', '');
            foreach ($providerOptions as $api => $providerLabel) {
                $providerSelect->addOption($providerLabel, $api);
            }
            $providerSelect->setSelected($currentProvider);
            $n['field'] = $providerSelect->get() . '<input type="hidden" id="vtrans-provider-reload" name="_reload_for_provider" value="0">';
        }
        $formElements[] = $n;

        // Key.
        $n = [];
        $n['label'] = '<label for="vtrans-connection-key">' . $this->i18n('vtrans_connections_key') . ' *</label>';
        $n['field'] = '<input type="text" class="form-control" id="vtrans-connection-key" name="connection_key" value="' . rex_escape($currentKey) . '" pattern="[a-z0-9_-]+" />';
        $n['note'] = '<p class="help-block">' . $this->i18n('vtrans_connections_key_note') . '</p>';
        $formElements[] = $n;

        // Label.
        $n = [];
        $n['label'] = '<label for="vtrans-connection-label">' . $this->i18n('vtrans_connections_label') . ' *</label>';
        $n['field'] = '<input type="text" class="form-control" id="vtrans-connection-label" name="label" value="' . rex_escape($currentLabel) . '" />';
        $formElements[] = $n;

        // Provider-driven fields: all fields from getConfigFields(), in order.
        if ('' !== $currentProvider && isset($availableProviders[$currentProvider])) {
            $providerInstance = $availableProviders[$currentProvider];
            $configFields = $providerInstance->getConfigFields();

            foreach ($configFields as $fieldName => $fieldDef) {
                // Skip timeout — rendered as a dedicated field below.
                if ('timeout' === $fieldName) {
                    continue;
                }
                $isColumn = !empty($fieldDef['column']);

                // Determine value: column fields map to dedicated DB columns, others come from params.
                if ($isColumn) {
                    $fieldValue = match ($fieldName) {
                        'api_key'       => $currentApiKey,
                        'api_url'       => $currentApiUrl,
                        'system_prompt' => $currentSystemPrompt,
                        'timeout'       => (string) $currentTimeout,
                        default         => $isFormPost
                            ? rex_post($fieldName, 'string', (string) ($fieldDef['default'] ?? ''))
                            : (string) ($currentParams[$fieldName] ?? ($fieldDef['default'] ?? '')),
                    };
                } else {
                    $fieldValue = $isFormPost
                        ? rex_post($fieldName, 'string', (string) ($currentParams[$fieldName] ?? ($fieldDef['default'] ?? '')))
                        : (string) ($currentParams[$fieldName] ?? ($fieldDef['default'] ?? ''));
                }

                $n = [];
                $n['label'] = '<label for="vtrans-connection-' . rex_escape($fieldName) . '">'
                    . rex_escape($fieldDef['label'])
                    . (!empty($fieldDef['required']) ? ' *' : '')
                    . '</label>';

                $defaultAttr = (isset($fieldDef['default']) && '' !== (string) $fieldDef['default'])
                    ? ' placeholder="' . rex_escape((string) $fieldDef['default']) . '"'
                    : '';

                if ('textarea' === ($fieldDef['type'] ?? 'text')) {
                    $n['field'] = '<textarea class="form-control" id="vtrans-connection-' . rex_escape($fieldName) . '" name="' . rex_escape($fieldName) . '" rows="3">' . rex_escape($fieldValue) . '</textarea>';
                } elseif ('api_key' === $fieldName && '' !== $fieldValue && !$isFormPost) {
                    // API Key field - REDAXO automatically adds a view button for password inputs
                    $n['field'] = '<input type="password" class="form-control" id="vtrans-connection-api-key" name="api_key" value="' . rex_escape($fieldValue) . '"' . $defaultAttr . ' />';
                } else {
                    $inputType = 'number' === ($fieldDef['type'] ?? 'text') ? 'number' : 'text';
                    $n['field'] = '<input type="' . $inputType . '" class="form-control" id="vtrans-connection-' . rex_escape($fieldName) . '" name="' . rex_escape($fieldName) . '" value="' . rex_escape($fieldValue) . '"' . $defaultAttr . ' />';
                }

                if (isset($fieldDef['note'])) {
                    $n['note'] = '<p class="help-block">' . rex_escape($fieldDef['note']) . '</p>';
                }

                $formElements[] = $n;
            }
        } elseif (!$isEditMode) {
            $n = [];
            $n['label'] = '';
            $n['field'] = '<p class="help-block text-muted"><i class="rex-icon fa-info-circle"></i> ' . $this->i18n('vtrans_connections_no_provider') . '</p>';
            $formElements[] = $n;
        }

        // Debug.
        $n = [];
        $n['label'] = '<label>Debug</label>';
        $n['field'] = '<input type="hidden" name="debug" value="0"><label class="control-label font-normal"><input type="checkbox" name="debug" value="1"' . ($currentDebug ? ' checked' : '') . '> ' . $this->i18n('vtrans_debug_activate') . '</label>';
        $formElements[] = $n;

        // Timeout.
        $n = [];
        $n['label'] = '<label for="vtrans-connection-timeout">' . $this->i18n('vtrans_connections_timeout') . '</label>';
        $n['field'] = '<input type="number" min="1" class="form-control" id="vtrans-connection-timeout" name="timeout" value="' . rex_escape((string) $currentTimeout) . '" placeholder="' . (int) VTrans::GLOBAL_TIMEOUT . '" style="max-width:180px" />';
        $n['note'] = '<p class="help-block">' . str_replace('{global}', (string) (int) VTrans::GLOBAL_TIMEOUT, $this->i18n('vtrans_connections_timeout_note')) . '</p>';
        $formElements[] = $n;

        // Max chars.
        $n = [];
        $n['label'] = '<label for="vtrans-connection-max-chars">' . $this->i18n('vtrans_connections_max_chars') . '</label>';
        $n['field'] = '<input type="number" min="1" class="form-control" id="vtrans-connection-max-chars" name="max_chars" value="' . rex_escape($currentMaxCharsRaw) . '" placeholder="' . (int) VTrans::GLOBAL_MAX_CHARS . '" style="max-width:180px" />';
        $n['note'] = '<p class="help-block">' . str_replace('{global}', (string) (int) VTrans::GLOBAL_MAX_CHARS, $this->i18n('vtrans_connections_max_chars_note')) . '</p>';
        $formElements[] = $n;

        // Playground.
        $n = [];
        $n['label'] = '<label>Playground</label>';
        $n['field'] = '<input type="hidden" name="playground" value="0"><label class="control-label font-normal"><input type="checkbox" name="playground" value="1"' . ($currentPlayground ? ' checked' : '') . '> ' . $this->i18n('vtrans_connections_playground') . '</label>';
        $formElements[] = $n;

        $fragment = new rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $content = $fragment->parse('core/form/form.php');

        // Buttons.
        $buttonElements = [];
        $n = [];
        $n['field'] = '<button class="btn btn-save rex-form-aligned" type="submit" name="connection-submit" value="1">' . $this->i18n('vtrans_save') . '</button>';
        $buttonElements[] = $n;

        if ($id > 0) {
            $n = [];
            $n['field'] = '<button class="btn btn-apply rex-form-aligned" type="submit" name="connection-apply" value="1">' . $this->i18n('vtrans_apply') . '</button>';
            $buttonElements[] = $n;
        }

        $n = [];
        $n['field'] = '<a class="btn btn-abort" href="' . rex_url::currentBackendPage() . '">' . $this->i18n('vtrans_cancel') . '</a>';
        $buttonElements[] = $n;

        $fragment = new rex_fragment();
        $fragment->setVar('flush', true);
        $fragment->setVar('elements', $buttonElements, false);
        $buttons = $fragment->parse('core/form/submit.php');

        $fragment = new rex_fragment();
        $fragment->setVar('class', 'edit');
        $fragment->setVar('title', $formTitle);
        $fragment->setVar('body', $content, false);
        $fragment->setVar('buttons', $buttons, false);
        $content = $fragment->parse('core/page/section.php');

        $formAction = rex_url::currentBackendPage(['func' => $func] + ($id > 0 ? ['id' => $id] : []));
        echo '<form action="' . $formAction . '" method="post">' . $content . '</form>';
    }
} else {
    // --- Connection list ---
    $connections = VTransConnection::getAll();

    $default = VTransConnection::getDefault();
    $defaultPlayground = VTransConnection::getDefaultPlayground();

    $addUrl = rex_url::currentBackendPage(['func' => 'add']);
    $thIcon = '<a class="rex-link-expanded" href="' . $addUrl . '" title="' . rex_escape($this->i18n('vtrans_connections_add')) . '"><i class="rex-icon rex-icon-add-action"></i></a>';

    if ([] === $connections) {
        echo rex_view::info($this->i18n('vtrans_connections_empty'));
        echo '<a class="btn btn-save" href="' . $addUrl . '"><i class="rex-icon rex-icon-add-action"></i> ' . $this->i18n('vtrans_connections_add') . '</a>';
    } else {
        $tableContent = '';
        $tableContent .= '<table class="table table-striped table-hover">';
        $tableContent .= '<thead><tr>';
        $tableContent .= '<th class="rex-table-icon">' . $thIcon . '</th>';
        $tableContent .= '<th>Key</th>';
        $tableContent .= '<th>Label</th>';
        $tableContent .= '<th>Provider</th>';
        $tableContent .= '<th class="rex-table-action">' . $this->i18n('vtrans_connections_default') . '</th>';
        $tableContent .= '<th class="rex-table-action"><i class="rex-icon fa-random" title="Playground"></i></th>';
        $tableContent .= '<th class="rex-table-action">' . $this->i18n('vtrans_connections_actions') . '</th>';
        $tableContent .= '</tr></thead>';
        $tableContent .= '<tbody>';

        foreach ($connections as $connection) {
            $editUrl = rex_url::currentBackendPage(['func' => 'edit', 'id' => $connection->getId()]);
            $deleteUrl = rex_url::currentBackendPage(['func' => 'delete', 'id' => $connection->getId()]);
            $togglePlaygroundUrl = rex_url::currentBackendPage(['func' => 'toggle_playground', 'id' => $connection->getId()]);

            $iconClass = 'rex-icon fa-plug';

            // Default toggle: radio-style, only one connection can be the default.
            if ($connection->isDefault()) {
                $defaultToggle = '<i class="fa fa-dot-circle-o text-success" title="' . rex_escape($this->i18n('vtrans_connections_default')) . '"></i>';
            } else {
                $setDefaultUrl = rex_url::currentBackendPage(['func' => 'set_default', 'id' => $connection->getId()]);
                $defaultToggle = '<a href="' . $setDefaultUrl . '" title="' . rex_escape($this->i18n('vtrans_connections_set_default')) . '"><i class="fa fa-circle-o text-muted"></i></a>';
            }

            // Playground toggle.
            if ($connection->isPlayground()) {
                $playgroundToggle = '<a href="' . $togglePlaygroundUrl . '" title="' . rex_escape($this->i18n('vtrans_connections_playground_off')) . '"><i class="fa fa-check-square-o text-success"></i></a>';
            } else {
                $playgroundToggle = '<a href="' . $togglePlaygroundUrl . '" title="' . rex_escape($this->i18n('vtrans_connections_playground_on')) . '"><i class="fa fa-square-o text-muted"></i></a>';
            }

            $tableContent .= '<tr>';
            $tableContent .= '<td class="rex-table-icon"><a class="rex-link-expanded" href="' . $editUrl . '" title="' . rex_escape($connection->getLabel()) . '"><i class="' . $iconClass . '"></i></a></td>';
            $tableContent .= '<td><a href="' . $editUrl . '">' . rex_escape($connection->getKey()) . '</a></td>';
            $tableContent .= '<td><a href="' . $editUrl . '">' . rex_escape($connection->getLabel()) . '</a></td>';
            $tableContent .= '<td><small>' . rex_escape($connection->getProvider()) . '</small></td>';
            $moveUpUrl = rex_url::currentBackendPage(['func' => 'move_up', 'id' => $connection->getId()]);
            $moveDownUrl = rex_url::currentBackendPage(['func' => 'move_down', 'id' => $connection->getId()]);
            $tableContent .= '<td class="rex-table-action" style="white-space:nowrap; text-align:center">' . $defaultToggle . '</td>';
            $tableContent .= '<td class="rex-table-action" style="white-space:nowrap">' . $playgroundToggle . '</td>';
            $tableContent .= '<td class="rex-table-action" style="white-space:nowrap">';
            $tableContent .= '<a href="' . $moveUpUrl . '" title="' . rex_escape($this->i18n('vtrans_connections_move_up')) . '"><i class="rex-icon fa-arrow-up"></i></a> ';
            $tableContent .= '<a href="' . $moveDownUrl . '" style="margin-left:10px;" title="' . rex_escape($this->i18n('vtrans_connections_move_down')) . '"><i class="rex-icon fa-arrow-down"></i></a> ';
            $tableContent .= '<a class="text-danger" style="margin-left:15px;" href="' . $deleteUrl . '" data-confirm="' . rex_escape($this->i18n('vtrans_connections_delete_confirm')) . '" title="' . rex_escape($this->i18n('vtrans_connections_delete')) . '"><i class="rex-icon rex-icon-delete"></i></a>';
            $tableContent .= '</td>';
            $tableContent .= '</tr>';
        }

        $tableContent .= '</tbody></table>';

        $fragment = new rex_fragment();
        $fragment->setVar('title', $this->i18n('vtrans_connections_title') . ' (' . count($connections) . ')');
        $fragment->setVar('content', $tableContent, false);
        echo $fragment->parse('core/page/section.php');
    }
}
