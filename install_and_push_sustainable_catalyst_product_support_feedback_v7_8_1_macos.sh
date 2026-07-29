#!/usr/bin/env bash
set -Eeuo pipefail
VERSION="7.8.1"
INSTALLER_REVISION="V7_8_1_PRIVATE_REPOSITORY_RELEASE_BRIDGE"
RELEASE_TITLE="Private Repository Release Bridge"
REMOTE="git@github.com:Content-Catalyst-LLC/sustainable-catalyst-product-support-feedback.git"
DOWNLOADS="${HOME}/Downloads"
CANONICAL_REPO_DIR="$DOWNLOADS/sustainable-catalyst-product-support-feedback"
LEGACY_REPO_DIR="$DOWNLOADS/sustainable-catalyst-feature-suggestions"
REPO_DIR="$CANONICAL_REPO_DIR"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TAG="v${VERSION}"
VALIDATOR_SCRIPT="validate_v7_8_1.sh"
REPO_ARCHIVE="sustainable-catalyst-product-support-feedback-v7.8.1-repository.zip"
BUNDLE_ARCHIVE="sustainable-catalyst-product-support-feedback-platform-v7.8.1-release-bundle.zip"
SUMS_ARCHIVE="sustainable-catalyst-product-support-feedback-v7.8.1-artifacts.sha256"
PLUGIN_SLUG="sustainable-catalyst-feature-suggestions"
fail(){ printf 'ERROR: %s\n' "$*" >&2; exit 1; }
select_python(){
  local candidate version
  for candidate in \
    /opt/homebrew/opt/python@3.13/bin/python3.13 \
    /opt/homebrew/bin/python3.13 \
    python3.13 \
    /opt/homebrew/opt/python@3.12/bin/python3.12 \
    /opt/homebrew/bin/python3.12 \
    python3.12; do
    if command -v "$candidate" >/dev/null 2>&1; then
      version="$("$candidate" -c 'import sys;print(f"{sys.version_info.major}.{sys.version_info.minor}")')"
      case "$version" in 3.12|3.13) printf '%s' "$candidate"; return 0;; esac
    fi
  done
  return 1
}
remote_tag_ref_oid(){ git ls-remote --tags origin "refs/tags/${TAG}" | awk 'NR==1{print $1}'; }
remote_tag_commit_oid(){
  local peeled direct
  peeled="$(git ls-remote --tags origin "refs/tags/${TAG}^{}" | awk 'NR==1{print $1}')"
  if [ -n "$peeled" ]; then printf '%s' "$peeled"; return; fi
  direct="$(remote_tag_ref_oid)"; [ -n "$direct" ] && printf '%s' "$direct"
}
create_release_tag(){
  if git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null 2>&1; then git tag -d "$TAG" >/dev/null; fi
  git tag -a "$TAG" -m "Product Support and Feedback Platform v${VERSION} — ${RELEASE_TITLE}"
}
push_release_tag(){
  local remote_ref remote_commit remote_tree local_tree
  remote_ref="$(remote_tag_ref_oid)"
  if [ -z "$remote_ref" ]; then git push origin "$TAG"; return; fi
  remote_commit="$(remote_tag_commit_oid)"
  remote_tree="$(git rev-parse "${remote_commit}^{tree}" 2>/dev/null || true)"
  local_tree="$(git rev-parse 'HEAD^{tree}')"
  if [ -n "$remote_tree" ] && [ "$remote_tree" = "$local_tree" ]; then
    git push --force-with-lease="refs/tags/${TAG}:${remote_ref}" origin "refs/tags/${TAG}:refs/tags/${TAG}"
    return
  fi
  fail "Remote tag ${TAG} exists and differs from this release tree."
}
verify_tree_parity(){
  "$VENV_PY" - "$1" "$2" <<'PYVERIFY'
from pathlib import Path
import hashlib,sys
ignored={'.git','.venv','venv','__pycache__'}
def inventory(root):
    root=Path(root).resolve()
    return {p.relative_to(root).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in root.rglob('*') if p.is_file() and not any(x in ignored for x in p.parts)}
a,b=inventory(sys.argv[1]),inventory(sys.argv[2])
missing=sorted(set(a)-set(b));extra=sorted(set(b)-set(a));changed=sorted(k for k in set(a)&set(b) if a[k]!=b[k])
if missing or extra or changed:
    print('Repository synchronization parity failed.',file=sys.stderr)
    print('Missing: '+', '.join(missing[:20]),file=sys.stderr)
    print('Unexpected: '+', '.join(extra[:20]),file=sys.stderr)
    print('Changed: '+', '.join(changed[:20]),file=sys.stderr)
    raise SystemExit(1)
print(f'PASS - checksum parity confirmed across {len(a)} repository files')
PYVERIFY
}
create_existing_repo_backups(){
  local source_dir timestamp base backup bundle
  source_dir="$1"
  timestamp="$(date +%Y%m%d-%H%M%S)"
  base="$(basename "$source_dir")"
  backup="$DOWNLOADS/${base}-before-v${VERSION}-${timestamp}.zip"
  bundle="$DOWNLOADS/${base}-before-v${VERSION}-${timestamp}.bundle"
  printf '==> Creating safety backups\n'
  (cd "$(dirname "$source_dir")" && zip -qr "$backup" "$base" -x '*/.git/*' '*/.venv/*' '*/venv/*')
  git -C "$source_dir" bundle create "$bundle" --all >/dev/null 2>&1 || rm -f "$bundle"
  printf 'Safety ZIP: %s\n' "$backup"
  [ -f "$bundle" ] && printf 'Git bundle: %s\n' "$bundle"
}
PYTHON_BIN="$(select_python || true)"
[ -n "$PYTHON_BIN" ] || fail "Python 3.13 or 3.12 is required. Install with: brew install python@3.13"
for command_name in git unzip rsync zip shasum; do command -v "$command_name" >/dev/null 2>&1 || fail "$command_name is required."; done
ARCHIVE=""
for candidate in "$SCRIPT_DIR/$BUNDLE_ARCHIVE" "$DOWNLOADS/$BUNDLE_ARCHIVE" "$SCRIPT_DIR/$REPO_ARCHIVE" "$DOWNLOADS/$REPO_ARCHIVE"; do
  if [ -f "$candidate" ]; then ARCHIVE="$candidate"; break; fi
done
[ -n "$ARCHIVE" ] || fail "Place the v7.8.1 repository ZIP or release bundle beside this installer or in ~/Downloads."
TMP="$(mktemp -d "${TMPDIR:-/tmp}/scpsf-v781.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT
printf '==> Product Support and Feedback Platform v%s installer\n' "$VERSION"
printf 'Installer revision: %s\n' "$INSTALLER_REVISION"
printf 'Python: %s\n' "$("$PYTHON_BIN" --version 2>&1)"
printf 'Archive: %s\n' "$ARCHIVE"
unzip -q "$ARCHIVE" -d "$TMP/archive"
SUMS_FILE="$(find "$TMP/archive" -maxdepth 3 -type f -name 'SHA256SUMS' -print -quit || true)"
if [ -z "$SUMS_FILE" ]; then
  for candidate in "$SCRIPT_DIR/SHA256SUMS" "$DOWNLOADS/SHA256SUMS" "$SCRIPT_DIR/$SUMS_ARCHIVE" "$DOWNLOADS/$SUMS_ARCHIVE"; do
    if [ -f "$candidate" ]; then SUMS_FILE="$candidate"; break; fi
  done
fi
[ -n "$SUMS_FILE" ] || fail "SHA256SUMS is required and was not found in the release bundle or beside the installer."
printf '==> Verifying release checksums\n'
if [ "$(dirname "$SUMS_FILE")" = "$(dirname "$ARCHIVE")" ] && [ "$(basename "$ARCHIVE")" = "$REPO_ARCHIVE" ]; then
  grep -F "  $REPO_ARCHIVE" "$SUMS_FILE" >/dev/null || fail "SHA256SUMS is missing an entry for $REPO_ARCHIVE."
  (cd "$(dirname "$ARCHIVE")" && shasum -a 256 -c "$(basename "$SUMS_FILE")" --ignore-missing) || fail "Release checksum verification failed."
else
  (cd "$(dirname "$SUMS_FILE")" && shasum -a 256 -c "$(basename "$SUMS_FILE")") || fail "Release checksum verification failed."
fi
printf 'PASS - release checksums verified\n'
REPO_ZIP="$(find "$TMP/archive" -type f -name "$REPO_ARCHIVE" -print -quit || true)"
if [ -n "$REPO_ZIP" ]; then
  mkdir -p "$TMP/repository"
  unzip -q "$REPO_ZIP" -d "$TMP/repository"
  SOURCE="$(find "$TMP/repository" -maxdepth 2 -type d -name 'sustainable-catalyst-product-support-feedback-v7.8.1-repository' -print -quit)"
else
  SOURCE="$(find "$TMP/archive" -maxdepth 2 -type d -name 'sustainable-catalyst-product-support-feedback-v7.8.1-repository' -print -quit)"
fi
[ -n "${SOURCE:-}" ] || fail "Could not locate the v7.8.1 repository source."
[ -f "$SOURCE/$VALIDATOR_SCRIPT" ] || fail "Missing $VALIDATOR_SCRIPT."
[ -f "$SOURCE/backend/requirements-validation.txt" ] || fail "Missing validation dependencies."
[ -f "$SOURCE/wordpress/$PLUGIN_SLUG/$PLUGIN_SLUG.php" ] || fail "WordPress compatibility plugin folder is missing."
[ ! -d "$SOURCE/wordpress/sustainable-catalyst-product-support-feedback" ] || fail "The WordPress plugin folder was incorrectly renamed."
grep -Fq 'VERSION="7.8.1"' "$SOURCE/$VALIDATOR_SCRIPT" || fail "Wrong validator version."
grep -Fq 'Version: 7.8.1' "$SOURCE/wordpress/$PLUGIN_SLUG/$PLUGIN_SLUG.php" || fail "Wrong plugin version."
grep -Fq 'Content-Catalyst-LLC/sustainable-catalyst-product-support-feedback' "$SOURCE/feature_suggestions_manifest.json" || fail "Canonical repository metadata is missing."
grep -Fq '"release_board"' "$SOURCE/feature_suggestions_manifest.json" || fail "Release board metadata is missing."
grep -Fq '"public_title": "Release Console"' "$SOURCE/feature_suggestions_manifest.json" || fail "Release Console metadata is missing."
grep -Fq '"release_intelligence": true' "$SOURCE/feature_suggestions_manifest.json" || fail "Release intelligence metadata is missing."
grep -Fq '"release_console_copy"' "$SOURCE/feature_suggestions_manifest.json" || fail "Release Console copy metadata is missing."
[ -f "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-release-console-copy.php" ] || fail "Release Console copy controller is missing."
grep -Fq "const OPTION_KEY = 'scfs_release_console_copy';" "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-release-console-copy.php" || fail "Release Console copy option is missing."
grep -Fq 'scfs_release_console_product_intelligence' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-release-board.php" || fail "Release intelligence rendering is missing."
grep -Fq "'layout' => 'terminal'" "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-release-board.php" || fail "Terminal Release Console layout is missing."
grep -Fq "'interval' => '7'" "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-release-board.php" || fail "Seven-second Release Console interval is missing."
grep -Fq 'data-console-action="toggle"' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-release-board.php" || fail "Release Console controls are missing."
grep -Fq '<noscript>' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-release-board.php" || fail "Release Console no-JavaScript fallback is missing."
grep -Fq 'data-console-announcer' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-release-board.php" || fail "Release Console announcer is missing."
grep -Fq 'MutationObserver' "$SOURCE/wordpress/$PLUGIN_SLUG/assets/release-console-v7.8.1.js" || fail "Dynamic Release Console initialization is missing."
grep -Fq '[data-console-active="true"]' "$SOURCE/wordpress/$PLUGIN_SLUG/assets/release-board-v7.8.1.css" || fail "Stable Release Console screen layout is missing."
grep -Fq -- '--scfs-release-console-columns:' "$SOURCE/wordpress/$PLUGIN_SLUG/assets/release-board-v7.8.1.css" || fail "Shared responsive Release Console grid is missing."
grep -Fq 'scfs-release-board__product-identity' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-release-board.php" || fail "Release intelligence is not anchored beneath product names."
grep -Fq 'No plugins awaiting review' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php" || fail "Plugin Discovery zero state is missing."
grep -Fq 'if ($unmatched)' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php" || fail "Plugin Discovery pending heading is not candidate-driven."
grep -Fq 'render_candidate_table' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php" || fail "Actionable Plugin Discovery candidate rows are missing."
grep -Fq 'Map to canonical product' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php" || fail "Canonical product mapping dropdown is missing."
grep -Fq "const MAPPINGS_OPTION = 'scfs_installed_plugin_discovery_mappings';" "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php" || fail "Administrator mapping storage is missing."
grep -Fq "'administrator_mapping'" "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php" || fail "Administrator mapping precedence is missing."
grep -Fq 'scfs_mapping_alias_collision' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php" || fail "Alias collision protection is missing."
grep -Fq 'Restore to review' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php" || fail "Ignored plugin restore is missing."
grep -Fq 'Remove manual mapping' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php" || fail "Manual mapping removal is missing."
grep -Fq '/product-registry/discovery/decision' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php" || fail "Mapping REST endpoint is missing."
[ -f "$SOURCE/wordpress/$PLUGIN_SLUG/assets/plugin-discovery-v7.8.1.js" ] || fail "Plugin Discovery mapping JavaScript is missing."
[ -f "$SOURCE/wordpress/$PLUGIN_SLUG/assets/plugin-discovery-v7.8.1.css" ] || fail "Plugin Discovery mapping stylesheet is missing."
grep -Fq 'Duplicate mapping review' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php" || fail "Duplicate Plugin Discovery review is not separated."
grep -Fq "\$record['discovered_plugin_version'] = '';" "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php" || fail "Stale Plugin Discovery status clearing is missing."
grep -Fq "'Analytics R'" "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-canonical-product-registry.php" || fail "Analytics R public label is missing."
grep -Fq "const SCHEMA = 'scfs-canonical-product-registry/2.1';" "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-canonical-product-registry.php" || fail "Registry schema 2.1 is missing."
grep -Fq 'integrity_report' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-canonical-product-registry.php" || fail "Registry integrity reporting is missing."
grep -Fq 'apply_v740_governance_migrations' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-canonical-product-registry.php" || fail "Registry governance migration tooling is missing."
grep -Fq 'apply_v750_release_intelligence_migrations' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-canonical-product-registry.php" || fail "Release intelligence migration tooling is missing."
grep -Fq 'product-registry/integrity' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-canonical-product-registry.php" || fail "Registry integrity REST route is missing."
grep -Fq 'product-registry/migrations' "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-canonical-product-registry.php" || fail "Registry migration REST route is missing."
[ -f "$SOURCE/schemas/scfs-canonical-product-registry-v2.schema.json" ] || fail "Registry schema 2.0 artifact is missing."
[ -f "$SOURCE/schemas/scfs-canonical-product-github-sync-v1.schema.json" ] || fail "GitHub synchronization schema is missing."
GITHUB_CLASS="$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-canonical-product-github-sync.php"
DISCOVERY_CLASS="$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php"
BOARD_CLASS="$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-release-board.php"
[ -f "$GITHUB_CLASS" ] || fail "Canonical Product GitHub synchronization controller is missing."
grep -Fq 'public function active_plugins_for_mapping()' "$DISCOVERY_CLASS" || fail "All-active-plugin dropdown source is missing."
grep -Fq 'Select a currently active site or network plugin.' "$DISCOVERY_CLASS" || fail "Active-plugin enforcement is missing."
grep -Fq 'scfs_save_product_connection' "$DISCOVERY_CLASS" || fail "Canonical product connection action is missing."
grep -Fq '/product-registry/github/webhook' "$GITHUB_CLASS" || fail "Signed GitHub webhook route is missing."
grep -Fq 'SCFS_GITHUB_WEBHOOK_SECRET' "$GITHUB_CLASS" || fail "GitHub webhook secret contract is missing."
grep -Fq 'SCFS_GITHUB_TOKEN' "$GITHUB_CLASS" || fail "Private GitHub repository token contract is missing."
GITHUB_SETTINGS_CLASS="$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-github-connection-settings.php"
[ -f "$GITHUB_SETTINGS_CLASS" ] || fail "Administrator GitHub Connection controller is missing."
grep -Fq "const OPTION_KEY = 'scfs_github_connection_settings';" "$GITHUB_SETTINGS_CLASS" || fail "GitHub Connection option is missing."
grep -Fq 'aes-256-gcm' "$GITHUB_SETTINGS_CLASS" || fail "Encrypted GitHub credential storage is missing."
grep -Fq 'Test repository access' "$GITHUB_SETTINGS_CLASS" || fail "Mapped repository connection testing is missing."
grep -Fq 'scfs_sync_all_canonical_github' "$GITHUB_SETTINGS_CLASS" || fail "Sync-all repository action is missing."
grep -Fq 'GitHub %1$s returned HTTP %2$d: %3$s' "$GITHUB_CLASS" || fail "Endpoint-aware GitHub HTTP diagnostics are missing."
grep -Fq 'console version synchronized from tag %s.' "$GITHUB_CLASS" || fail "Semantic-tag synchronization state is missing."
grep -Fq 'No GitHub Release or semantic version tag is published; repository and commit evidence were synchronized.' "$GITHUB_CLASS" || fail "Activity-only synchronization state is missing."
grep -Fq "'footer_repository_url' => ''" "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-release-console-copy.php" || fail "Editable repository destination is missing."
grep -Fq "'footer_support_url' => '/support/'" "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-release-console-copy.php" || fail "Editable support destination is missing."
[ -f "$SOURCE/schemas/scfs-github-connection-settings-v1.schema.json" ] || fail "GitHub Connection schema is missing."
grep -Fq "array('hourly', 'twicedaily', 'daily', 'disabled')" "$GITHUB_CLASS" || fail "Configurable GitHub polling governance is missing."
grep -Fq "/tags?per_page=100" "$GITHUB_CLASS" || fail "Semantic Git tag fallback is missing."
grep -Fq "ensure_schedule" "$GITHUB_CLASS" || fail "Hourly schedule self-repair is missing."
grep -Fq "footer_repository_url" "$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-github-connection-settings.php" || fail "Unified footer controls are missing from GitHub Connection."
grep -Fq "\$record['version_source'] = \$version_source;" "$GITHUB_CLASS" || fail "GitHub release/tag-to-console version propagation is missing."
grep -Fq 'scfs_release_board_repository_url' "$BOARD_CLASS" || fail "Canonical repository footer resolver is missing."
if grep -Fq "home_url('/support/releases/')" "$BOARD_CLASS"; then fail "Legacy ./releases footer route remains public."; fi
RELEASE_OPERATIONS_CLASS="$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-release-operations-admin.php"
[ -f "$RELEASE_OPERATIONS_CLASS" ] || fail "Release Operations administration controller is missing."
grep -Fq "const ADMIN_SLUG = 'scfs-release-operations';" "$RELEASE_OPERATIONS_CLASS" || fail "Release Operations admin route is missing."
grep -Fq 'function freshness_state' "$RELEASE_OPERATIONS_CLASS" || fail "Release Operations freshness governance is missing."
grep -Fq 'function audit_registry' "$RELEASE_OPERATIONS_CLASS" || fail "Release Operations integrity auditing is missing."
grep -Fq 'function stabilize_action' "$RELEASE_OPERATIONS_CLASS" || fail "One-click Release Operations stabilization is missing."
grep -Fq 'function footer_health' "$RELEASE_OPERATIONS_CLASS" || fail "Release Console footer verification is missing."
grep -Fq 'active_plugins_for_mapping' "$RELEASE_OPERATIONS_CLASS" || fail "Live active-plugin mapping integrity checks are missing."
grep -Fq 'function clear_error_fields' "$GITHUB_CLASS" || fail "Stale GitHub error clearing is missing."
grep -Fq 'github_sync_endpoint_url' "$GITHUB_CLASS" || fail "GitHub endpoint diagnostics are missing."
grep -Fq 'scfs_release_board_cache_invalidated' "$BOARD_CLASS" || fail "Release Console cache invalidation observability is missing."
[ -f "$SOURCE/tests/test-v761-github-diagnostics-runtime.php" ] || fail "Inherited v7.6.1 GitHub recovery runtime contract is missing."
[ -f "$SOURCE/tests/test-v761-release-operations-stabilization.php" ] || fail "Inherited v7.6.1 stabilization contract is missing."
grep -Fq 'scfs products operations-report' "$RELEASE_OPERATIONS_CLASS" || fail "Release Operations WP-CLI report is missing."
[ -f "$SOURCE/wordpress/$PLUGIN_SLUG/assets/release-operations-v7.8.1.js" ] || fail "Release Operations JavaScript is missing."
[ -f "$SOURCE/wordpress/$PLUGIN_SLUG/assets/release-operations-v7.8.1.css" ] || fail "Release Operations stylesheet is missing."
[ -f "$SOURCE/schemas/scfs-release-operations-v1.schema.json" ] || fail "Release Operations schema is missing."
PRODUCT_EDITOR_CLASS="$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-product-connection-editor.php"
[ -f "$PRODUCT_EDITOR_CLASS" ] || fail "Product Connection Editor controller is missing."
grep -Fq "const ADMIN_SLUG = 'scfs-product-connection-editor';" "$PRODUCT_EDITOR_CLASS" || fail "Product Connection Editor admin route is missing."
grep -Fq 'Every currently active site or network plugin is available.' "$PRODUCT_EDITOR_CLASS" || fail "All-active-plugin editor dropdown is missing."
grep -Fq 'Connection history' "$PRODUCT_EDITOR_CLASS" || fail "Product connection history is missing."
grep -Fq 'Reset inherited values' "$PRODUCT_EDITOR_CLASS" || fail "Product connection reset control is missing."
[ -f "$SOURCE/schemas/scfs-product-connection-editor-v1.schema.json" ] || fail "Product Connection Editor schema is missing."
[ -f "$SOURCE/tests/test-v762-product-connection-runtime.php" ] || fail "Product Connection Editor runtime contract is missing."
REGISTRY_ADMIN_CLASS="$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-canonical-product-registry-admin.php"
[ -f "$REGISTRY_ADMIN_CLASS" ] || fail "Canonical Product Registry Administration controller is missing."
grep -Fq "const VERSION = '7.8.1';" "$REGISTRY_ADMIN_CLASS" || fail "Canonical Product Registry Administration version is missing."
grep -Fq 'Search products' "$REGISTRY_ADMIN_CLASS" || fail "Searchable registry controls are missing."
grep -Fq 'Release Console order' "$REGISTRY_ADMIN_CLASS" || fail "Release Console ordering controls are missing."
grep -Fq 'scfs_registry_merge_products' "$REGISTRY_ADMIN_CLASS" || fail "Duplicate-product merge workflow is missing."
grep -Fq 'scfs_registry_import_dry_run' "$REGISTRY_ADMIN_CLASS" || fail "Registry import dry run is missing."
grep -Fq 'create_backup' "$REGISTRY_ADMIN_CLASS" || fail "Automatic registry backups are missing."
grep -Fq 'Registry change history' "$REGISTRY_ADMIN_CLASS" || fail "Administrator-attributed registry history is missing."
[ -f "$SOURCE/wordpress/$PLUGIN_SLUG/assets/canonical-product-registry-admin-v7.8.1.js" ] || fail "Registry administration JavaScript is missing."
[ -f "$SOURCE/wordpress/$PLUGIN_SLUG/assets/canonical-product-registry-admin-v7.8.1.css" ] || fail "Registry administration stylesheet is missing."
[ -f "$SOURCE/schemas/scfs-canonical-product-registry-v2.1.schema.json" ] || fail "Canonical registry schema 2.1 is missing."
[ -f "$SOURCE/schemas/scfs-canonical-product-registry-admin-v1.schema.json" ] || fail "Registry administration schema is missing."
[ -f "$SOURCE/tests/test-v770-canonical-registry-administration.php" ] || fail "Registry administration source contract is missing."
[ -f "$SOURCE/tests/test-v770-canonical-registry-runtime.php" ] || fail "Registry administration runtime contract is missing."
DISCOVERY_INTELLIGENCE_CLASS="$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-installed-plugin-discovery.php"
grep -Fq 'get_mu_plugins' "$DISCOVERY_INTELLIGENCE_CLASS" || fail "Must-use plugin inventory is missing."
grep -Fq 'get_dropins' "$DISCOVERY_INTELLIGENCE_CLASS" || fail "Drop-in inventory is missing."
grep -Fq 'suggestion_candidates' "$DISCOVERY_INTELLIGENCE_CLASS" || fail "Confidence-ranked canonical suggestions are missing."
grep -Fq 'apply_bulk_decision' "$DISCOVERY_INTELLIGENCE_CLASS" || fail "Bulk mapping and ignore workflow is missing."
grep -Fq 'version_comparison' "$DISCOVERY_INTELLIGENCE_CLASS" || fail "Installed-versus-GitHub version comparison is missing."
[ -f "$SOURCE/wordpress/$PLUGIN_SLUG/assets/plugin-discovery-v7.8.1.js" ] || fail "Plugin Discovery Intelligence JavaScript is missing."
[ -f "$SOURCE/wordpress/$PLUGIN_SLUG/assets/plugin-discovery-v7.8.1.css" ] || fail "Plugin Discovery Intelligence stylesheet is missing."
[ -f "$SOURCE/schemas/scfs-plugin-discovery-intelligence-v1.schema.json" ] || fail "Plugin Discovery Intelligence schema is missing."
[ -f "$SOURCE/tests/test-v771-plugin-discovery-intelligence.php" ] || fail "Inherited Plugin Discovery Intelligence source contract is missing."
[ -f "$SOURCE/tests/test-v771-plugin-discovery-runtime.php" ] || fail "Inherited Plugin Discovery Intelligence runtime contract is missing."
grep -Fq '"plugin_discovery_intelligence": true' "$SOURCE/feature_suggestions_manifest.json" || fail "Plugin Discovery Intelligence release metadata is missing."
GITHUB_INTELLIGENCE_CLASS="$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-github-release-intelligence.php"
[ -f "$GITHUB_INTELLIGENCE_CLASS" ] || fail "Private Repository Release Bridge controller is missing."
grep -Fq "const VERSION = '7.8.1';" "$GITHUB_INTELLIGENCE_CLASS" || fail "Private Repository Release Bridge version is missing."
grep -Fq 'select_release' "$GITHUB_CLASS" || fail "Governed GitHub release selection is missing."
grep -Fq 'github_prerelease' "$GITHUB_CLASS" || fail "Explicit prerelease authority is missing."
grep -Fq 'response_meta' "$GITHUB_CLASS" || fail "GitHub response metadata capture is missing."
grep -Fq 'record_webhook_delivery' "$GITHUB_CLASS" || fail "Webhook delivery governance is missing."
[ -f "$SOURCE/wordpress/$PLUGIN_SLUG/assets/github-release-intelligence-v7.8.1.js" ] || fail "Private Repository Release Bridge JavaScript is missing."
[ -f "$SOURCE/wordpress/$PLUGIN_SLUG/assets/github-release-intelligence-v7.8.1.css" ] || fail "Private Repository Release Bridge stylesheet is missing."
[ -f "$SOURCE/schemas/scfs-github-release-intelligence-v1.schema.json" ] || fail "Private Repository Release Bridge schema is missing."
[ -f "$SOURCE/tests/test-v780-github-release-intelligence.php" ] || fail "Private Repository Release Bridge source contract is missing."
[ -f "$SOURCE/tests/test-v780-github-release-intelligence-runtime.php" ] || fail "Private Repository Release Bridge runtime contract is missing."
grep -Fq '"github_release_intelligence": true' "$SOURCE/feature_suggestions_manifest.json" || fail "Private Repository Release Bridge release metadata is missing."
SETTINGS_CLASS="$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-github-connection-settings.php"
REGISTRY_CLASS="$SOURCE/wordpress/$PLUGIN_SLUG/includes/class-scfs-canonical-product-registry.php"
[ -f "$SETTINGS_CLASS" ] || fail "GitHub App connection settings are missing."
grep -Fq "const SCHEMA = 'scfs-github-connection-settings/1.1';" "$SETTINGS_CLASS" || fail "GitHub App settings schema 1.1 is missing."
grep -Fq 'function github_app_jwt' "$SETTINGS_CLASS" || fail "RS256 GitHub App JWT signing is missing."
grep -Fq '/access_tokens' "$SETTINGS_CLASS" || fail "GitHub App installation-token exchange is missing."
grep -Fq "'contents' => 'read'" "$SETTINGS_CLASS" || fail "Read-only Contents permission is missing."
grep -Fq "'metadata' => 'read'" "$SETTINGS_CLASS" || fail "Read-only Metadata permission is missing."
grep -Fq "\$repository_visibility !== 'public'" "$REGISTRY_CLASS" || fail "Fail-closed private repository visibility is missing."
grep -Fq "'github_latest_commit_sha' => \$private_repository ? ''" "$REGISTRY_CLASS" || fail "Private commit redaction is missing."
[ -f "$SOURCE/tests/test-v781-private-repository-release-bridge.php" ] || fail "GitHub App bridge runtime contract is missing."
[ -f "$SOURCE/schemas/scfs-github-connection-settings-v1.schema.json" ] || fail "GitHub App settings schema is missing."
grep -Fq '"private_repository_release_bridge": true' "$SOURCE/feature_suggestions_manifest.json" || fail "Private repository bridge capability metadata is missing."
grep -Fq '"canonical_registry_administration": true' "$SOURCE/feature_suggestions_manifest.json" || fail "Registry administration release metadata is missing."
grep -Fq '"all_products_operational_table": true' "$SOURCE/feature_suggestions_manifest.json" || fail "Release Operations metadata is missing."
grep -Fq '"all_active_plugins_selectable": true' "$SOURCE/feature_suggestions_manifest.json" || fail "Active-plugin connection metadata is missing."
grep -Fq '"github_webhook_sync": true' "$SOURCE/feature_suggestions_manifest.json" || fail "GitHub synchronization metadata is missing."
grep -Fq '"repository_footer_link": true' "$SOURCE/feature_suggestions_manifest.json" || fail "Repository footer metadata is missing."
if ! "$PYTHON_BIN" - "$SOURCE/$VALIDATOR_SCRIPT" <<'PYBASH'
from pathlib import Path
import re,sys
for line in Path(sys.argv[1]).read_text().splitlines():
    if 'Bash 4 compatibility scan' in line:
        continue
    if re.search(r'(^|\s)(mapfile|readarray)(\s|$)', line):
        raise SystemExit(1)
PYBASH
then fail "Validator requires Bash 4."; fi
bash -n "$SOURCE/$VALIDATOR_SCRIPT" || fail "Invalid validator shell syntax."
if [ "${SCPSF_PREFLIGHT_ONLY:-0}" = "1" ]; then
  printf 'PREFLIGHT PASSED: selected the v7.8.1 Private Repository Release Bridge package and preserved the WordPress plugin identity.\n'
  exit 0
fi
printf '==> Verifying canonical GitHub repository access\n'
if ! git ls-remote "$REMOTE" HEAD >/dev/null 2>&1; then
  fail "The canonical GitHub repository is not reachable. Confirm the repository name and SSH access, then run this installer again."
fi
printf 'PASS - canonical GitHub repository is reachable\n'
printf '==> Preparing compatible Python validation environment\n'
"$PYTHON_BIN" -m venv "$TMP/venv"
VENV_PY="$TMP/venv/bin/python"
"$VENV_PY" -m pip install --upgrade pip >/dev/null
printf '==> Installing backend and validation dependencies\n'
"$VENV_PY" -m pip install --only-binary=pydantic-core -r "$SOURCE/backend/requirements-validation.txt"
"$VENV_PY" -c 'import fastapi,httpx,pydantic,pytest' || fail "Validation packages missing."
printf '==> Validating packaged source\n'
PYTHON_BIN="$VENV_PY" bash "$SOURCE/$VALIDATOR_SCRIPT"
if [ -d "$CANONICAL_REPO_DIR/.git" ]; then
  REPO_DIR="$CANONICAL_REPO_DIR"
  create_existing_repo_backups "$REPO_DIR"
elif [ -d "$LEGACY_REPO_DIR/.git" ]; then
  [ ! -e "$CANONICAL_REPO_DIR" ] || fail "Both legacy and canonical local paths exist. Move or remove the conflicting canonical path before continuing."
  create_existing_repo_backups "$LEGACY_REPO_DIR"
  printf '==> Renaming local repository folder\n'
  mv "$LEGACY_REPO_DIR" "$CANONICAL_REPO_DIR"
  REPO_DIR="$CANONICAL_REPO_DIR"
  printf 'PASS - local repository renamed to %s\n' "$REPO_DIR"
elif [ -e "$CANONICAL_REPO_DIR" ]; then
  fail "$CANONICAL_REPO_DIR exists but is not a Git repository."
elif [ -e "$LEGACY_REPO_DIR" ]; then
  fail "$LEGACY_REPO_DIR exists but is not a Git repository."
else
  printf '==> Cloning canonical repository main\n'
  git clone --branch main "$REMOTE" "$CANONICAL_REPO_DIR"
  REPO_DIR="$CANONICAL_REPO_DIR"
fi
printf '==> Synchronizing local main with canonical origin/main\n'
git -C "$REPO_DIR" remote set-url origin "$REMOTE"
git -C "$REPO_DIR" fetch origin main --tags
git -C "$REPO_DIR" reset --hard
git -C "$REPO_DIR" clean -fd
git -C "$REPO_DIR" checkout -B main origin/main
printf '==> Installing v7.8.1 source with checksum verification\n'
rsync -a --checksum --delete --exclude '.git/' --exclude '.venv/' --exclude 'venv/' "$SOURCE/" "$REPO_DIR/"
printf '==> Verifying post-sync source parity\n'
verify_tree_parity "$SOURCE" "$REPO_DIR"
cd "$REPO_DIR"
PYTHON_BIN="$VENV_PY" bash "./$VALIDATOR_SCRIPT"
git diff --check
git add -A
if git diff --cached --quiet; then
  printf 'No source changes to commit. Current main may already contain v7.8.1.\n'
else
  git commit -m "Product Support and Feedback Platform v7.8.1 — $RELEASE_TITLE"
fi
printf '==> Refreshing origin/main before push\n'
git fetch origin main
if ! git merge-base --is-ancestor origin/main HEAD; then
  git rebase origin/main
  PYTHON_BIN="$VENV_PY" bash "./$VALIDATOR_SCRIPT"
  git diff --check
fi
create_release_tag
printf '==> Pushing main\n'
if ! git push origin HEAD:main; then
  git fetch origin main
  git rebase origin/main
  PYTHON_BIN="$VENV_PY" bash "./$VALIDATOR_SCRIPT"
  git diff --check
  create_release_tag
  git push origin HEAD:main
fi
printf '==> Pushing tag %s\n' "$TAG"
push_release_tag
printf '\nSUCCESS: v7.8.1 validated, installed, committed, tagged, and pushed.\n'
printf 'Local repository: %s\n' "$REPO_DIR"
printf 'WordPress plugin folder preserved: %s\n' "$PLUGIN_SLUG"
