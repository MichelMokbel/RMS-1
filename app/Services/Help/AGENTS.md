# Help content, search, and assistance

## Overview

This area owns approved help search, cited assistant answers, and screenshot capture metadata. You can keep help visibility aligned with the user's actual module access.

## Key files

| File | Owns |
|---|---|
| `HelpSearchService.php` | Visible article search and context construction. |
| `HelpBotService.php` | Chat history, structured answers, citations, and fallback responses. |
| `HelpCaptureService.php` | Screenshot capture manifest and demo workflow support. |
| `app/Support/Help/MarkdownRenderer.php` | Help Markdown rendering. |
| `resources/views/livewire/help/`, `resources/views/components/help/bot.blade.php` | Help UI. |
| `config/help.php` | Locale, bot, and capture settings. |

## Conventions

* Search and assistant context are built for a specific user and locale. Visibility filtering belongs before sending article context to a provider.
* The bot persists user and assistant messages in a help chat session.
* Missing context, disabled assistance, provider failures, empty answers, or missing citations lead to a fallback.
* Provider access uses `app/Services/Ai/AiProviderInterface.php`, allowing a fake in tests.

## Gotchas

* A structured answer is not proof that every citation is valid or authorized. You can preserve visibility checks at any new display or retrieval boundary.
* Demo seeding and screenshot capture commands can create data or artifacts. They are not routine production audit commands.
* Relevant tests are in `tests/Feature/Help/HelpCenterTest.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
