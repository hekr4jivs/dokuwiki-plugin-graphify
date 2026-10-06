<?php
/** Copyright (c) 2026 Heinrich Krupp. SPDX-License-Identifier: GPL-2.0-only */
if (!defined('DOKU_INC')) die();

class action_plugin_graphify extends \dokuwiki\Extension\ActionPlugin
{
    public function register(\dokuwiki\Extension\EventHandler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'freshPdf', null, -50);
    }

    public function freshPdf(\dokuwiki\Extension\Event $event, $param)
    {
        if (!in_array($event->data, ['export_pdf','export_pdfbook','export_pdfns'], true)) return;
        global $INPUT;
        // dw2pdf's final document cache is shared across readers and bypasses media ACL
        // checks. Rebuild PDFs for each request; validated PNGs still use a private cache.
        $INPUT->set('purge', true);
    }
}
