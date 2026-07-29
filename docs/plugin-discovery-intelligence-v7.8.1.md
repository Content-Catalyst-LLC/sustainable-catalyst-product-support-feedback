# GitHub Release Intelligence v7.8.1

GitHub Release Intelligence classifies every WordPress plugin implementation and connects its evidence to the Canonical Product Registry without creating products automatically.

## Inventory

The inventory includes standard plugins, site-active plugins, network-active plugins, inactive plugins, must-use plugins, and WordPress drop-ins. Plugin file identifiers remain WordPress-relative and private.

## Suggestions

Suggestions are ranked from governed signals: administrator mappings, exact and legacy plugin files, SC Product headers, folder slugs, text domains, mapped GitHub repository URLs, and approved product names. Confidence and contributing signals are shown to administrators. Tied or weak suggestions remain awaiting review.

## Bulk review

Administrators can select multiple review rows and either map each plugin to its unique high-confidence suggestion or mark the selected plugins as outside the Sustainable Catalyst registry. Ambiguous, low-confidence, or duplicate-target rows are skipped for individual review.

## Version intelligence

Installed plugin versions are compared with the latest governed GitHub release or semantic tag. The inventory distinguishes current, update available, ahead of GitHub, and unknown states.

## Consistency diagnostics

The diagnostics layer reports duplicate product mappings, SC Product ID header conflicts, plugin repository mismatches, malformed versions, and registry alias collisions. No diagnostic publishes plugin paths or private repository data publicly.
