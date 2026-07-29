# `[sc_release_board]` v7.8.1 attributes

Existing attributes remain compatible: `layout`, `context`, `groups`, `products`, `limit`, `show_status`, `show_updated`, `show_links`, `show_header`, `show_footer`, `show_source`, `dense`, `inactive`, `title`, `heading_level`, `interval`, and `rotate`.

v7.8.1 adds:

- `show_intelligence`
- `intro` and `standard_intro`
- `foundation_label`
- `research_intelligence_label`
- `data_analysis_label`
- `creation_systems_label`
- `commercial_label`
- `previous_label`, `pause_label`, `play_label`, and `next_label`
- `release_label` and `support_label`
- `empty_message` and `unavailable_message`

Shortcode attributes override presentation copy only. They cannot change registry-governed product facts.


## Footer link settings

The default footer destinations are controlled from **Support & Feedback → Release Console Copy → Footer links**.

- `Repository label` controls the visible `./repository` text.
- `Repository destination` overrides the canonical GitHub repository. Leave it blank to follow the `product-support-feedback` registry mapping.
- `Support label` controls the visible `./support` text.
- `Support destination` defaults to `/support/` and accepts a site-relative path or complete URL.

Shortcode attributes `repository_url` and `support_url` can override these settings for an individual console instance while preserving the existing `show_links` and `show_footer` controls.
