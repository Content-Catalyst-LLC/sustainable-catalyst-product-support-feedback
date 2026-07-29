# Canonical Product Registry Administration v7.8.1

Open **Support & Feedback → Product Registry** to manage the authoritative product catalog.

## Catalog controls

The screen supports full-text search plus family, lifecycle, and visibility filters. Each product row links to the Product Connection Editor and exposes non-destructive archive or restore controls.

## Release Console ordering

Products included in the Release Console appear in one of five governed families. Drag a product to reorder it. Keyboard users can focus a product and use **Alt+Up/Down** to reorder within a family or **Alt+Left/Right** to move between families. Saving assigns stable ten-point order increments.

## Product creation and duplicate merge

New products require an immutable canonical ID, public name, family, and product type. Duplicate merges transfer legacy names and plugin identifiers to the selected authoritative target. The source remains as an archived superseded record so historical references continue to resolve.

## Archive and restore

Archiving removes a product from public and homepage surfaces without deleting its canonical record. Restoration returns the record to Maintenance state for administrator review.

## Import, export, and rollback

Registry imports must pass a dry run before Apply becomes available. The report identifies added, changed, and removed canonical IDs and includes the full integrity result. Applying an import creates a backup first. All create, merge, reorder, archive, restore, import, and rollback operations create administrator-attributed history records.

## Security and privacy

Exports include governed product records and integrity evidence but never include GitHub tokens, webhook secrets, or private credentials. Public interfaces continue to exclude private plugin paths and internal repository metadata.
