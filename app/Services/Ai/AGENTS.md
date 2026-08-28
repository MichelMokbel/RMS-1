# Structured AI provider boundary

## Overview

This area exposes structured generation to application services. Gemini is the current implementation, while callers depend on a provider interface.

## Key files

| File | Owns |
|---|---|
| `AiProviderInterface.php` | The `generateStructured(messages, schema)` contract. |
| `GeminiProvider.php` | HTTP payload, role mapping, response extraction, and JSON parsing. |
| `app/Providers/AppServiceProvider.php` | Provider binding. |
| `config/services.php` | Gemini key, model, and base URL settings. |

## Conventions

* You can add or replace a provider behind the existing interface and binding rather than coupling callers to an SDK.
* The current provider requests JSON using a response schema and maps assistant messages to the provider's model role.
* Missing credentials, failed HTTP status, empty content, and invalid JSON raise errors. Callers choose how to recover.
* HTTP calls have a 30 second timeout. Provider responses remain untrusted input even when JSON parsing succeeds.

## Gotchas

* JSON parsing alone is not full domain validation. Schema requirements and authorization remain relevant at the consuming service.
* Help is the current service consumer of this interface. Marketing provider synchronization uses its own API services. Secrets and message content do not belong in error output or committed examples.
* `tests/Feature/Help/HelpCenterTest.php` binds a fake implementation of the interface. You can use the same boundary without making a live model request.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
