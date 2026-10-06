<?php
/** Copyright (c) 2026 Heinrich Krupp. SPDX-License-Identifier: GPL-2.0-only */
if (!defined('DOKU_INC')) die();

/** Static PDF output. Only the administrator can select a rendering backend. */
class GraphifyPdf
{
    private $plugin;
    public function __construct($plugin) { $this->plugin = $plugin; }

    private function quote($value)
    {
        $value = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', (string) $value);
        return '"' . str_replace(["\\", '"', "\r", "\n", "\t"], ["\\\\", '\\"', '', '\\n', ' '], $value) . '"';
    }

    /** Use generated identifiers and quoted plain-text labels, never DOT from authors. */
    public function dot(array $model)
    {
        $arch = $model['format'] === 'archify';
        $directed = $model['directed'];
        $link = $directed ? ' -> ' : ' -- ';
        $lines = [($directed ? 'digraph' : 'graph') . ' Diagram {',
            'graph [bgcolor="white", pad="0.25", dpi=144, fontname="sans-serif", fontsize=16,',
            'label=' . $this->quote($model['title']) . ', labelloc=t, ' .
                ($arch ? 'layout=neato, overlap=true, splines=true' : 'rankdir=LR, nodesep=0.5, ranksep=0.8') . '];',
            'node [shape=box, style="rounded,filled", fontname="sans-serif", fontsize=12, color="#486581", margin="0.15,0.12"];',
            'edge [fontname="sans-serif", fontsize=10, arrowsize=0.7];'];
        $palette = ['#dce8f7', '#d7eff8', '#e0eddc', '#f7e3dc', '#fff1d6', '#ece1f7'];
        $types = ['external'=>'#ececed', 'database'=>'#d7eff8', 'frontend'=>'#e0eddc', 'security'=>'#f7e3dc'];
        $groups = []; $ids = [];
        foreach ($model['nodes'] as $i => $node) {
            $ids[$node['id']] = 'n' . $i;
            if (!isset($groups[$node['group']])) $groups[$node['group']] = $palette[count($groups) % count($palette)];
            $label = $node['label'];
            $extra = '';
            if ($arch) {
                foreach (['sublabel', 'tag'] as $field) if ($node[$field] !== '') $label .= "\n" . $node[$field];
                // Neato positions are inches; invert the screen's downward y axis.
                $pos = sprintf('%.5f,%.5f!', $node['x'] / 72, -$node['y'] / 72);
                $extra = ', pin=true, pos=' . $this->quote($pos) . sprintf(', width=%.5f, height=%.5f', $node['width']/72, $node['height']/72);
                $label = wordwrap($label, max(18, (int) ($node['width']/7)), "\n", false);
            } else $label = wordwrap($label, 32, "\n", false);
            $fill = $arch ? ($types[$node['group']] ?? '#dce8f7') : $groups[$node['group']];
            $lines[] = 'n' . $i . ' [label=' . $this->quote($label) . ', fillcolor=' . $this->quote($fill) . $extra . '];';
        }
        foreach ($model['edges'] as $edge) {
            $label = $edge['label']; $style = 'solid'; $color = '#2c5eaa'; $width = 1.2;
            if ($arch) {
                if ($edge['variant'] === 'dashed') $style = 'dashed';
                if ($edge['variant'] === 'security') $color = '#881207';
                if ($edge['variant'] === 'emphasis') $width = 2;
            } else {
                $label .= "\n[" . $edge['confidence'] . ']';
                if ($edge['confidence'] === 'INFERRED' || $edge['confidence'] === 'UNSPECIFIED') { $style='dashed'; $color='#6e6e6e'; }
                if ($edge['confidence'] === 'AMBIGUOUS') { $style='dotted'; $color='#881207'; }
            }
            $lines[] = $ids[$edge['from']] . $link . $ids[$edge['to']] . ' [label=' . $this->quote(wordwrap($label, 36, "\n", false)) .
                ', style=' . $this->quote($style) . ', color=' . $this->quote($color) . ', penwidth=' . $width . '];';
        }
        $memberships = 0;
        foreach ($model['hyperedges'] as $i => $hyper) {
            $memberships += count($hyper['nodes']);
            if ($memberships > 20000) throw new \RuntimeException('Too many hyperedge memberships for PDF rendering.');
            $lines[] = 'h' . $i . ' [shape=diamond, fillcolor="#fff1d6", label=' . $this->quote('Hyperedge: ' . $hyper['label']) . '];';
            foreach (array_unique($hyper['nodes']) as $member)
                $lines[] = 'h' . $i . $link . $ids[$member] . ' [style=dashed, color="#947600", dir=none, constraint=false];';
        }
        $lines[] = '}';
        return implode("\n", $lines);
    }

    public function html(array $model)
    {
        global $conf;
        $backend = rtrim((string) $this->plugin->getConf('pdf_backend_url'), '/');
        $url = parse_url($backend);
        if (!$url || !in_array($url['scheme'] ?? '', ['http','https'], true) || empty($url['host']) ||
            isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']))
            throw new \RuntimeException('PDF rendering requires an administrator-configured Kroki backend (graphify: pdf_backend_url).');
        $dot = $this->dot($model);
        $file = $conf['cachedir'] . '/graphify-pdf/' . hash('sha256', 'v1|' . $backend . '|' . $dot) . '.png';
        $png = is_file($file) ? file_get_contents($file) : false;
        if (!$this->validPng($png)) {
            $png = $this->fetch($backend . '/graphviz/png', $dot);
            if (!$this->validPng($png)) throw new \RuntimeException('PDF diagram rendering failed. Check the configured Kroki backend.');
            io_mkdir_p(dirname($file));
            io_saveFile($file, $png);
        }
        $html = '<div class="graphify-pdf"><img src="data:image/png;base64,' . base64_encode($png) .
            '" style="max-width:100%;" alt="' . hsc($model['title']) . '" /></div>';
        // Cards stay searchable and readable even when the diagram must shrink to the paper width.
        foreach ($model['cards'] as $card) {
            $html .= '<p><strong>' . hsc($card['title']) . '</strong></p><ul>';
            foreach ($card['items'] as $item) $html .= '<li>' . hsc($item) . '</li>';
            $html .= '</ul>';
        }
        return $html;
    }

    private function validPng($png)
    {
        if (!is_string($png) || strlen($png) > 20*1024*1024 || substr($png,0,8) !== "\x89PNG\r\n\x1a\n") return false;
        $size = @getimagesizefromstring($png);
        return $size && $size[2] === IMAGETYPE_PNG && $size[0] <= 16000 && $size[1] <= 16000 && $size[0]*$size[1] <= 40000000;
    }

    /** Bound response size, refuse redirects, and retain TLS verification. */
    protected function fetch($url, $dot)
    {
        $timeout = max(1, min(60, (int) $this->plugin->getConf('pdf_timeout')));
        $limit = 20*1024*1024;
        if (function_exists('curl_init')) {
            $body = ''; $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$dot,
                CURLOPT_HTTPHEADER=>['Content-Type: text/plain; charset=utf-8'], CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_TIMEOUT=>$timeout, CURLOPT_CONNECTTIMEOUT=>min(5,$timeout),
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>static function ($ch, $chunk) use (&$body, $limit) {
                    if (strlen($body)+strlen($chunk)>$limit) return 0;
                    $body .= $chunk; return strlen($chunk);
                }]);
            $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
            return $ok !== false && $status === 200 ? $body : false;
        }
        $context = stream_context_create(['http'=>['method'=>'POST', 'header'=>"Content-Type: text/plain; charset=utf-8\r\n",
            'content'=>$dot, 'timeout'=>$timeout, 'follow_location'=>0, 'ignore_errors'=>true],
            'ssl'=>['verify_peer'=>true, 'verify_peer_name'=>true]]);
        $stream = @fopen($url, 'rb', false, $context);
        if (!$stream) return false;
        $meta = stream_get_meta_data($stream);
        $body = stream_get_contents($stream, $limit+1); fclose($stream);
        $status = $meta['wrapper_data'][0] ?? '';
        return preg_match('/^HTTP\/\S+ 200\b/', $status) && strlen($body)<=$limit ? $body : false;
    }
}
