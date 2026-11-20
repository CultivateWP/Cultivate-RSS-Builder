# Change Log

All notable changes to this project will be documented in this file, formatted via [this recommendation](http://keepachangelog.com/).

## [1.2.0] = 2026-11-20

- Add a native block-editor RSS title panel that saves with the normal post REST update, retaining a classic-editor fallback.
- Keep both feed URLs working when administrators create feeds at the same time.
- Preserve category restrictions for legacy unchecked newsletter settings and timezone offsets in copied feed links.
- Preserve existing feed hashes, serialized options, RSS-title metadata, international taxonomy slugs, and legacy feed parameters.
- Require an administrator capability and nonce-protected POST for feed creation; keep public feed URL reads free of settings writes.
- Validate settings and title inputs, check object-level edit permissions, and escape admin and feed output.
- Honor the requested GMT flag when using modified timestamps.
- Require PHP 7.0 or newer for safe decoding of the plugin's extra legacy serialization layer.
- Add WordPress coding standards, PHP compatibility checks, JavaScript lint/tests, disposable WordPress integration tests, and CI configuration.

## [1.1.0] = 2025-09-05
- Add option for "exclude posts published since". Useful for creating a feed of updated posts and excluding any newly published posts (see README).

## [1.0.0] = 2023-07-21
- Initial release. Forked from older plugin, BE RSS Builder. Option names left the same so previously built RSS feeds continue working after upgrading from BE RSS Builder.
