# Plugin Discovery review workflow — v7.8.1

Plugin Discovery derives its status from the current candidate queue rather than a stale cached count.

Unmatched and malformed candidates appear under **Pending private review**. Deterministic duplicate candidates appear under **Duplicate mapping review**. Each row now includes an administrator-controlled canonical-product dropdown, evidence details, and a save action.

A candidate can be mapped to an eligible canonical product, left awaiting review, or marked **Not a Sustainable Catalyst product**. Manual mappings have first priority during discovery and persist governed file, slug, text-domain, and name identifiers. Collision checks prevent one installed plugin identity from belonging to multiple canonical products.

Ignored candidates move to a reversible review panel. Administrator-created mappings can also be removed, restoring the product's prior identifier snapshot. A rescan clears stale discovery evidence and recalculates matched, pending, duplicate, ignored, and manual-mapping counts.

When no actionable candidates remain, the administrator screen omits the pending heading and displays **No plugins awaiting review**. Authenticated REST fragments update this state immediately; nonce-protected forms retain the same workflow without JavaScript.
