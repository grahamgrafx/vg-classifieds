# vg-classifieds: Agent Instructions

This file captures project-specific guidance for work in `vg-classifieds`.

## Primary Principle

- Compatibility with **WordPress core** is paramount.
- Compatibility with **Newspack Plugin** (`wp-content/plugins/newspack-plugin`) is paramount.
- When trade-offs arise, choose the implementation that preserves behavior and stability with both WordPress core and Newspack Plugin.

## Compatibility Requirements

- Prefer WordPress APIs, hooks, and coding patterns over custom alternatives when possible.
- Avoid changes that alter existing data structures, option keys, post meta keys, or hook signatures unless strictly required.
- Keep admin and frontend behavior predictable in Newspack environments; do not introduce assumptions that bypass Newspack integrations.
- If a change might affect Newspack Plugin interactions, validate the impact in `wp-content/plugins/newspack-plugin` before finalizing.

## References

- Newspack Plugin repository: [https://github.com/Automattic/newspack-plugin/](https://github.com/Automattic/newspack-plugin/)
