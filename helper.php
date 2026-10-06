<?php
/** Copyright (c) 2026 Heinrich Krupp. SPDX-License-Identifier: GPL-2.0-only */
/** Validate JSON data; no HTML, script, image URL or renderer options from authors. */
if (!defined('DOKU_INC')) die();

class helper_plugin_graphify extends \dokuwiki\Extension\Plugin
{
    private function text($value)
    {
        if ($value === null) return '';
        if (!is_scalar($value)) throw new \InvalidArgumentException('Expected a text value.');
        $value = (string) $value;
        if (strlen($value) > 4096) throw new \InvalidArgumentException('Text value exceeds 4096 bytes.');
        return $value;
    }

    public function media($id)
    {
        $id = ltrim($id, ':');
        if (!preg_match('/^[a-z0-9][a-z0-9_:.-]*\.json$/D', $id) || strpos($id, '..') !== false)
            throw new \InvalidArgumentException('Use a canonical .json media ID.');
        if (auth_quickaclcheck(ltrim(getNS($id) . ':*', ':')) < AUTH_READ)
            throw new \InvalidArgumentException('JSON media unavailable.');
        $file = mediaFN($id);
        if (!is_file($file) || filesize($file) > 2 * 1024 * 1024)
            throw new \InvalidArgumentException('JSON media unavailable or larger than 2 MiB.');
        return $this->model(file_get_contents($file));
    }

    public function model($json)
    {
        if (!is_string($json) || strlen($json) > 2 * 1024 * 1024)
            throw new \InvalidArgumentException('JSON exceeds 2 MiB.');
        $raw = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($raw)) throw new \InvalidArgumentException('Expected a JSON object.');
        $arch = isset($raw['components']);
        if (!$arch && isset($raw['directed']) && !is_bool($raw['directed']))
            throw new \InvalidArgumentException('directed must be true or false.');
        if ($arch && (($raw['schema_version'] ?? null) !== 1 || ($raw['diagram_type'] ?? '') !== 'architecture'))
            throw new \InvalidArgumentException('Only Archify architecture schema v1 is supported.');
        if ($arch && !empty($raw['boundaries']))
            throw new \InvalidArgumentException('Archify boundaries are not yet supported.');
        $nodes = $arch ? ($raw['components'] ?? null) : ($raw['nodes'] ?? null);
        $edges = $arch ? ($raw['connections'] ?? null) : ($raw['links'] ?? $raw['edges'] ?? null);
        if (!is_array($nodes) || !array_is_list($nodes) || !is_array($edges) || !array_is_list($edges))
            throw new \InvalidArgumentException('Expected nodes/links or components/connections arrays.');
        if (!count($nodes) || count($nodes) > 2000 || count($edges) > 10000)
            throw new \InvalidArgumentException('Use 1–2000 nodes and at most 10000 edges.');
        $model = ['format' => $arch ? 'archify' : 'graphify', 'directed' => $arch || ($raw['directed'] ?? false),
            'title' => $this->text($raw['meta']['title'] ?? $raw['graph']['title'] ?? 'Graph'),
            'nodes' => [], 'edges' => [], 'hyperedges' => [], 'cards' => []];
        $ids = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['id']) || (!is_string($node['id']) && !is_int($node['id'])))
                throw new \InvalidArgumentException('Every node needs a string or integer ID.');
            $id = $this->text($node['id']);
            if ($id === '' || isset($ids[$id])) throw new \InvalidArgumentException('Empty or duplicate node ID.');
            $ids[$id] = true;
            $entry = ['id' => $id, 'label' => $this->text($node['label'] ?? $id),
                'group' => $this->text($arch ? ($node['type'] ?? 'component') : ($node['community'] ?? 'unassigned')),
                'groupLabel' => $this->text($arch ? ($node['type'] ?? 'component') : ($node['community_name'] ?? 'Community ' . ($node['community'] ?? 'unassigned'))),
                'sublabel' => $this->text($node['sublabel'] ?? ''), 'tag' => $this->text($node['tag'] ?? ''),
                'metadata' => $node];
            if ($arch) {
                $pos = $node['pos'] ?? null; $size = $node['size'] ?? null;
                foreach ([$pos, $size] as $pair) {
                    if (!is_array($pair) || count($pair) !== 2 || !is_numeric($pair[0]) || !is_numeric($pair[1])
                        || !is_finite((float) $pair[0]) || !is_finite((float) $pair[1])
                        || abs($pair[0]) > 100000 || abs($pair[1]) > 100000)
                        throw new \InvalidArgumentException('Archify components require finite pos and size pairs.');
                }
                if ($size[0] <= 0 || $size[1] <= 0) throw new \InvalidArgumentException('Component size must be positive.');
                $entry['x'] = $pos[0] + $size[0] / 2; $entry['y'] = $pos[1] + $size[1] / 2;
                $entry['width'] = $size[0]; $entry['height'] = $size[1];
            }
            $model['nodes'][] = $entry;
        }
        foreach ($edges as $index => $edge) {
            if (!is_array($edge)) throw new \InvalidArgumentException('Invalid edge.');
            $from = $this->text($edge[$arch ? 'from' : 'source'] ?? null);
            $to = $this->text($edge[$arch ? 'to' : 'target'] ?? null);
            if (!isset($ids[$from], $ids[$to])) throw new \InvalidArgumentException('Edge references an unknown node.');
            $model['edges'][] = ['id' => (string) $index, 'from' => $from, 'to' => $to,
                'label' => $this->text($edge[$arch ? 'label' : 'relation'] ?? $edge['label'] ?? ''),
                'confidence' => $arch ? '' : $this->text($edge['confidence'] ?? 'UNSPECIFIED'),
                'variant' => $arch ? $this->text($edge['variant'] ?? '') : '', 'metadata' => $edge];
        }
        $hyperedges = $raw['hyperedges'] ?? $raw['graph']['hyperedges'] ?? [];
        if (!is_array($hyperedges) || count($hyperedges) > 1000)
            throw new \InvalidArgumentException('Invalid or excessive hyperedges.');
        foreach ($hyperedges as $hyper) {
            if (!is_array($hyper) || !is_array($hyper['nodes'] ?? null) || count($hyper['nodes']) < 2)
                throw new \InvalidArgumentException('Hyperedges require at least two nodes.');
            $members = [];
            foreach ($hyper['nodes'] as $member) {
                $member = $this->text($member);
                if (!isset($ids[$member])) throw new \InvalidArgumentException('Hyperedge references an unknown node.');
                $members[] = $member;
            }
            $model['hyperedges'][] = ['nodes' => $members, 'label' => $this->text($hyper['label'] ?? $hyper['id'] ?? 'Hyperedge')];
        }
        if ($arch) {
            $cards = $raw['cards'] ?? [];
            if (!is_array($cards) || count($cards) > 100) throw new \InvalidArgumentException('Invalid cards.');
            foreach ($cards as $card) {
                if (!is_array($card) || !is_array($card['items'] ?? null)) throw new \InvalidArgumentException('Invalid card.');
                $items = [];
                foreach ($card['items'] as $item) $items[] = $this->text($item);
                $model['cards'][] = ['title' => $this->text($card['title'] ?? ''), 'items' => $items];
            }
        }
        return $model;
    }
}
