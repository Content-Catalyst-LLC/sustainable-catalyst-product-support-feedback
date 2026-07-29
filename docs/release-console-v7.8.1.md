# Release Console v7.8.1

Use `[sc_release_board]` to render the governed Sustainable Catalyst Release Console.

## Copy administration

Open **Product Support → Release Console Copy** in WordPress. Editing copy changes presentation language only. Product names, versions, release dates, lifecycle state, validation status, documentation readiness, and known-issue counts remain controlled by the Product Registry.

## Common overrides

```text
[sc_release_board title="Platform Releases" intro="Current governed platform releases." foundation_label="Core Systems" interval="7"]
```

Available copy overrides include `title`, `intro`, `standard_intro`, the five screen labels, previous/pause/play/next labels, Release and Support footer labels, and empty or unavailable messages.

## Release intelligence

Set product intelligence in **Product Support → Product Registry**. The console can display previous version, release date, change summary, validation state, documentation state, known-issue count, recently-updated state, maintenance state, and superseded state.
