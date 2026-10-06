# Graphify JSON Renderer for DokuWiki

Render Graphify JSON directly as an interactive graph in a DokuWiki page.
No generated HTML document, iframe, graph database or external rendering service
is required. The bundled renderer library is served by your Wiki.

## Installation

Requires PHP 8.1 or newer and a modern browser with JavaScript enabled.

In DokuWiki's Extension Manager, install this release ZIP:

https://github.com/hekr4jivs/dokuwiki-plugin-graphify/releases/latest/download/graphify.zip

Alternatively, extract the release archive into `lib/plugins/`; the resulting
directory must be `lib/plugins/graphify/`. Reload cached Wiki assets after installing
or updating the plugin. No template, `htmlok` or MIME configuration change is needed.

This is an independent community plugin, not an official Graphify-Labs integration.
It renders existing graph data; it does not run Graphify extraction.

## Paste JSON into the page

Put the block in the page source, outside a `<code>` block:

```text
<graphify>
{
  "directed": true,
  "nodes": [
    {"id": "app", "label": "Application", "community": 0},
    {"id": "db", "label": "Database", "community": 1}
  ],
  "links": [
    {"source": "app", "target": "db", "relation": "uses", "confidence": "EXTRACTED"}
  ]
}
</graphify>
```

You can also reference an existing JSON medium using its full namespace ID:

```text
{{graphify>team:diagrams:architecture.json}}
```

The plugin checks read permission for the media namespace. Normal DokuWiki MIME
rules apply when uploading JSON; inline blocks do not require an upload. Readers
can access all graph data included in a page they may read. There is no per-node ACL.

## Explore the graph

- Drag the background to pan, scroll to zoom, or use the zoom and Fit buttons.
- Search node labels, IDs, source files and tags.
- Inspect node/edge metadata and follow neighbouring nodes.
- Toggle the details panel, communities, confidence filters and edge labels.
- Set a path start in node details, select another node and trace the visible path.
  Directed graphs follow edge direction.
- Switch theme, enter fullscreen or download the visible canvas as PNG.

The canvas uses the full Wiki content column initially. Details are optional.
Fullscreen uses the available viewport without changing the Wiki template.

## PDF export with dw2pdf

The same JSON blocks and media references automatically become static PNG diagrams
in [dw2pdf](https://www.dokuwiki.org/plugin:dw2pdf) exports. Browser interaction stays
unchanged. Install a Kroki instance with Graphviz support and select its approved URL
in **Configuration Settings > graphify > pdf_backend_url**. The default is empty;
the plugin sends no graph data to a rendering service until you configure one.

Alternatively, add server-local settings to `conf/local.protected.php`:

```php
$conf['plugin']['graphify']['pdf_backend_url'] = 'http://your-kroki:8000';
$conf['plugin']['graphify']['pdf_timeout'] = 30;
```

Choose a private/self-hosted backend for confidential graphs. HTTPS certificate
verification stays enabled and redirects are refused. Authors cannot choose the
backend or provide DOT attributes, image paths or remote URLs. Responses are
bounded to 20 MiB, 16000 pixels per dimension and 40 million pixels in total.
Failures produce a visible error in the PDF rather than an empty diagram.

The static layout includes all nodes and connections, independently of a reader's
current browser filters or drag positions. Graphify uses a Graphviz layout with
community colors and confidence labels/styles. Hyperedges appear as labelled
diamonds joined to their members. Archify keeps initial positions, labels, sublabels,
tags and edge variants; explanatory cards become searchable text below the image.
Static routing and styling can differ from the interactive canvas. Very large
graphs may exceed renderer limits or need smaller views for legible paper output.

PNG results are cached in the Wiki's private data cache. No public media asset is
created. JSON validation and media ACL checks happen before the image cache is read.
The plugin forces a fresh document render for each dw2pdf page, book or namespace
export because dw2pdf's shared final PDF cache would otherwise skip those checks.
Cached PNGs avoid repeated Kroki calls. This also retries earlier renderer failures.

## Supported JSON

**Graphify:** node-link exports with `nodes`, `links`, `directed`, community IDs and
relationship metadata. Extraction-style `nodes`/`edges` is also accepted. Confidence
distinguishes `EXTRACTED`, `INFERRED`, `AMBIGUOUS` and `UNSPECIFIED`. Hyperedges are
drawn as labelled group regions. An undirected graph keeps undirected connections.

**Archify:** architecture schema v1 with `components`, `connections`, explicit
`pos`/`size`, labels, sublabels, tags and explanatory cards. This is a separate input
format. Initial component positions are preserved; the renderer computes its own
connections. Archify HTML presets, animations, routing hints and original export
features are not reproduced. Nonempty boundaries and other diagram types fail
explicitly rather than disappearing.

Limits: 2 MiB of input, 2000 nodes, 10000 edges, 1000 hyperedges, 100 cards and
4096 bytes per plain-text field. These are validation limits, not a performance
guarantee for every graph at the maximum size. Duplicate IDs, malformed JSON and
missing connection endpoints fail explicitly.

## Data and security

JSON is data, not executable configuration. Author-provided labels, tooltips and
metadata use text nodes; PHP escapes script-breakout sequences. Authors cannot
supply script/image URLs or arbitrary renderer options. Referenced media ACLs are
checked before embedding data in the page response, and render caching is disabled.

Interactive graph data remains in the browser. The browser renderer does not call
an AI service, Graphify backend, Kroki or a CDN. The optional PDF renderer sends
validated diagram text to the administrator-configured Kroki instance. Bundled vis-network is included in DokuWiki's
standard cached JavaScript bundle. Other plugins may have their own network behavior.

## Development and checks

```sh
php tests/php-smoke.php
php tests/pdf-smoke.php
npm ci
npx playwright install chromium
npm run test:browser
python tools/build_release.py
```

The PHP checks cover both schemas, malformed inputs, referential integrity, media
ACL denial and escaped hostile labels. PDF checks cover DOT escaping, direction,
hyperedges, positions, cards, backend failure, PNG validation and export cache handling. Browser checks cover multiple instances,
search, filters, zoom, paths, theme, fullscreen, PNG download and narrow screens.
All fixtures are synthetic. The release builder includes only runtime files,
documentation and licenses in `dist/graphify.zip`.

To use an existing Chrome installation in browser tests, set
`GRAPHIFY_CHROME_EXECUTABLE` to its executable path. End-to-end behavior with a
particular Wiki template and other plugins still needs verification on that Wiki.

## Dependencies and license

Plugin code: [GPL-2.0-only](LICENSE).
Bundled vis-network 9.1.9: [MIT](vendor/LICENSE-MIT).
See [THIRD_PARTY.md](THIRD_PARTY.md) for the pinned source and checksum.

[Graphify exporter](https://github.com/Graphify-Labs/graphify/blob/main/graphify/export.py)
and [vis-network documentation](https://visjs.github.io/vis-network/docs/network/)
describe the upstream format and rendering API.
