# Canonical Product Connections v7.8.1

Each canonical product connects three operational records:

1. Canonical Product Registry identity
2. Active WordPress plugin implementation
3. GitHub repository and governed release evidence

## Stabilized mapping checks

Release Operations compares `plugin_file` and `discovered_active` against WordPress’s current active site and network plugin lists. It reports:

- a registry record that claims a missing or inactive plugin is active
- an active mapped plugin whose discovery snapshot is stale
- duplicate plugin implementations
- duplicate GitHub repositories
- enabled GitHub synchronization without a repository

The audit does not rewrite mappings. Administrators resolve mismatches through Plugin Discovery, then run stabilization again.

## Preserved identity

The release preserves:

- canonical product IDs
- historical aliases
- manual mapping decisions
- GitHub repository mappings
- the `sustainable-catalyst-feature-suggestions` WordPress folder
- every legacy shortcode and public compatibility route
