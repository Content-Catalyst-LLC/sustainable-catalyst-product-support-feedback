# Batch 2 application audit and ten review-only drafts

Ten newly authored layered draft guides are in `articles.json` and `articles/`. The four source repositories are public. Their immutable source links are safe public-review candidates; publication still requires human editorial and scientific review.

These are ten locally source/example-checked drafts, **zero fully UI-verified or production-verified guides**. They provide novice terminology and numbered local-source/API exercises, scientific assumptions/limitations, copyable synthetic JSON, expected numerical outputs and developer references. They do not claim verified beginner browser clicks or screenshots. All runnable local examples passed. Full application coverage is incomplete.

## Actual receipts

- `receipt-workbench.json`: 5 local source assertions passed (root residual 1.2e-13, integral 0.33333333333333, refined sine integral 2.00000010827001, no scientific certification, linear residual zero).
- `receipt-site-intelligence.json`: 6 local source assertions passed (positive area; idempotent registration; synthetic key redaction; two spatial matches; mean 10 with one missing/no imputation; evidence packet checksum).
- `receipt-lab.json`: 7 local source assertions passed (protocol/readiness/hash; incomplete protocol rejected; run hashes; comparison mean 10.1/relative range ~0.01980198; report verification; tampering rejected).
- `receipt-decision-studio.json`: 4 local source assertions passed (baseline scores 70/67.5; missing evaluation null; equal-weight scores 60/75; conditional ordering change).

Additional existing source tests actually run:

- Lab `backend/tests/test_experiment_framework_v0300.py`: 5 passed.
- Site Intelligence `backend/tests/test_geospatial_analysis_spatial_evidence_v2150.py -k 'bbox_polygon or registration_redacts or intersection_and_aggregation'`: 3 passed, 6 deselected.
- Decision Studio `backend/tests/test_backend.py -k 'test_tradeoff_matrix_build_scores_and_diagnostics_v230 or test_tradeoff_matrix_missing_cells_visible_v230 or test_v250_scenario_comparison_surfaces_conditional_score_ranges_and_ordering_changes or test_v250_scenario_set_validates_matrix_references'`: 4 passed.
- Workbench selected upstream solver tests: collection FAILED because `app.main` imports missing `app.v10110` at line 566. This is a source launch blocker, not a failed numerical fixture. Direct solver-module examples passed. No application source was changed to make acceptance pass.

Initial Site/Workbench test commands lacked `PYTHONPATH=backend` and failed collection. This path configuration was diagnosed and corrected; the subsequent Site tests passed and Workbench exposed the missing-module blocker above. TestClient emitted a dependency deprecation warning recommending httpx2; it did not fail the passing tests.

## Reproduce the examples

Use Python 3.14.7 and `requirements.lock.txt` in an isolated virtual environment. Place each cited repository checkout under the adjacent `apps/{workbench,site-intelligence,lab,decision-studio}` directory at the SHA in `coverage-summary.json`. From this folder run:

```sh
python run_examples.py --app workbench
python run_examples.py --app site-intelligence
python run_examples.py --app lab
python run_examples.py --app decision-studio
```

The runner performs no external requests. Site storage is a temporary directory. Lab time is controlled only inside the deterministic software fixture; real measurements must retain truthful timestamps. Decision timestamps are not asserted as reproducible. Source and fixture input hashes, units, expected values and tolerances are part of the draft guides and receipts. The numerical fixtures test software behavior, not scientific conclusions.

## Coverage scope

`application-inventory.json` extracts public Python functions/classes and literal route decorators from the four `backend/app` trees. Counts are symbol/route candidates, not a certified capability count: aliases, router prefixes, UI controls, dynamic registrations and other source trees need further reconciliation. Every untouched entry is explicitly STATIC_EXTRACTED_NOT_MANUALLY_VERIFIED and GAP. Selected methods with actual fixture receipts are marked TESTED with DRAFT coverage. `coverage-summary.json` gives concise totals; full application audit is false for each application.

The current Workbench root, integral and linear source modules work in the local fixture despite the full backend import blocker. All four deployed services, authentication, browser rendering, permissions and cross-product delivery remain UNVERIFIED. Do not infer production availability from the source name or version.

## Rights and privacy

New guide prose: CC-BY-4.0, attribution to Content Catalyst LLC / Sustainable Catalyst documentation contributors. Synthetic JSON fixtures: CC0-1.0. No measurements, private research, personal data or live secrets are included. The synthetic `api_token` property deliberately tests property-name redaction and contains no credential. Redaction is not comprehensive anonymization.
