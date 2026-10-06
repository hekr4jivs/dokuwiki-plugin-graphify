# Changelog

## 0.2.0 - 2026-10-06

- Render Graphify and Archify as server-generated PNGs during dw2pdf exports.
- Configure an approved Kroki backend; no default external rendering service.
- Preserve direction, labels, confidence, communities, fixed architecture positions,
  hyperedge membership and readable, searchable architecture cards in PDFs.
- Rebuild exported PDFs to check JSON media permissions on every request; cache PNGs
  privately by validated source and selected backend.
- Register JSON media dependencies and add PDF/security smoke checks.


## 0.1.0, 2026-10-06

- Render inline Graphify JSON or JSON media references directly in DokuWiki.
- Add search, node and edge inspection, filters, zoom, path tracing, theme,
  fullscreen and PNG export.
- Support Graphify hyperedges and Archify architecture schema v1.
- Validate input bounds, references and media namespace read permissions.
- Bundle the renderer locally and include synthetic PHP/browser checks.
