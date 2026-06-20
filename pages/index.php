<?php

/** @var rex_addon $this */

// Render page headline once and delegate body rendering to active subpage.
echo rex_view::title($this->i18n('vtrans_title'));
rex_be_controller::includeCurrentPageSubPath();
