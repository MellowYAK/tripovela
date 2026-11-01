# Verification notes

Release preparation: 1 November 2026.

## Checks performed

| Check | Result and scope |
| --- | --- |
| First-run installation | A separate empty local installation created its databases and administrator, wrote private configuration, and redirected to sign-in. Invalid passwords were rejected. Existing installations refused another setup token. |
| Local English pages | Fifteen public and administrative page responses were checked for PHP warnings and unexpected Hebrew text. Passed. Native language-picker names are intentionally preserved. |
| Multilingual page rendering | The same fifteen pages were checked in all 14 languages: 210 responses passed the Hebrew-leak and PHP-warning checks. This does not grade translation fluency. |
| Additional administration pages | Articles, links, maintenance, settings, partners and community history were rendered in all 14 languages: 84 responses passed the Hebrew-leak and PHP-warning checks. |
| Translation coverage | New installer and administration dictionaries were checked for matching keys, nonempty values and matching placeholders in all 14 languages. |
| PHP syntax | 170 PHP files passed the local PHP 8.0 syntax check. |
| JavaScript syntax | 49 browser JavaScript files passed Node syntax checks. |
| Python helpers | Distributed Python helpers passed compilation. |
| Product captures | Actual public trip, expanded map and gallery screenshots; local administration and installation screenshots. See the product gallery for their context. |
| Distribution isolation | Runtime storage, credentials, databases, uploaded photos and production catalog records are excluded. Analytics identifiers are blank and measurement defaults are disabled. |

The automated page checks inspect rendered HTML; they are not a full browser interaction test or a native-language editorial review. Existing localization dictionaries remain in use alongside explicit translation keys. Hebrew dictionary keys in source code are not themselves evidence of untranslated output.

## Limits and follow-up QA

- Not every possible account state, validation error, modal or administrative action has been exercised in every language.
- Native speakers have not reviewed all 14 language editions.
- The screenshots do not establish a complete mobile, accessibility or cross-browser regression pass.
- A new installation is intentionally empty. It does not reproduce the live site's content, members, private reports or integration configuration.
- External email, advertising, affiliate and analytics integrations require the installer's own configuration and separate verification.
- CI checks are structural checks, not proof that all product workflows pass.

For further QA, use a separate empty installation: complete setup, sign in, create and refresh a trip as a guest and a member, check explicit sharing updates and recipient permissions, test English and RTL layouts at mobile widths, and exercise failure/retry paths. Never use a production database as a public test fixture.
