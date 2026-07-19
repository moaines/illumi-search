# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅ Active |

## Reporting a Vulnerability

If you discover a security vulnerability in Laravel FTS, please **do not** open a public issue.

Instead, use the **"Report a vulnerability"** feature on GitHub:
https://github.com/moaines/illumi-search/security/advisories/new

You will receive a response within **72 hours**. If the vulnerability is accepted,
a fix will be released as soon as possible, typically within 7 days.

## Scope

This policy covers the `moaines/illumi-search` package only. For vulnerabilities in
dependencies (Laravel, Filament, Spatie packages, etc.), please report to the
respective project.

## Security Considerations

- Laravel FTS stores index data in a local SQLite file. Ensure the file is not
  publicly accessible (default location: `storage/app/search/fts-index.sqlite`).
- The FTS index contains processed (normalized) copies of your model data.
  If you handle sensitive data, consider what columns are indexed in
  `$ftsSearchable` and whether snippets should be enabled.
