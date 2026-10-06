<?php
/** Copyright (c) 2026 Heinrich Krupp. SPDX-License-Identifier: GPL-2.0-only */
/** Inline <graphify>JSON</graphify> or {{graphify>namespace:graph.json}}. */
if (!defined('DOKU_INC')) die();

class syntax_plugin_graphify extends \dokuwiki\Extension\SyntaxPlugin
{
    public function getType() { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort() { return 158; }
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('<graphify>[\s\S]*?</graphify>', $mode, 'plugin_graphify');
        $this->Lexer->addSpecialPattern('\{\{graphify>[^}]+\}\}', $mode, 'plugin_graphify');
    }
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        return strpos($match, '<graphify>') === 0
            ? ['inline', substr($match, 10, -11)] : ['media', trim(substr($match, 11, -2))];
    }
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode !== 'xhtml') return false;
        $renderer->info['cache'] = false;
        try {
            $helper = plugin_load('helper', 'graphify');
            $model = $data[0] === 'inline' ? $helper->model($data[1]) : $helper->media($data[1]);
            $json = json_encode($model, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
            $renderer->doc .= '<div class="graphify-widget" style="width:100%;min-width:0;box-sizing:border-box">'
                . '<script type="application/json" class="graphify-data">' . $json . '</script>'
                . '<p class="graphify-loading">Loading interactive graph…</p>'
                . '<noscript>Enable JavaScript to use the interactive graph.</noscript></div>';
        } catch (\Throwable $error) {
            $renderer->doc .= '<p class="graphify-error">Graphify: ' . hsc($error->getMessage()) . '</p>';
        }
        return true;
    }
}
