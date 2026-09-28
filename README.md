# LLM Auto Redirect

Version 1.3.3 — an admin-side assistant for reviewing 404 errors and creating redirects with the Redirection plugin.

## Requirements

- WordPress 5.0 or later
- PHP 7.4 or later
- The Redirection plugin, installed and active

## What it does

- Lists recent 404 URLs that do not already have redirects.
- Asks a configured language model to suggest a destination from the site-page list.
- Lets an administrator review or edit a single suggestion before creating its redirect.
- Offers an explicitly confirmed bulk action that creates redirects only from the approved page list.

Model-generated destinations are rejected unless they are valid absolute URLs and exactly match a destination included in the prompt. A deliberate manual destination entered by an administrator in the single-item form may be external; review it before creating the redirect.

## Installation

1. Build the installable ZIP with `python scripts/package_release.py`, or create `wp-content/plugins/llm-auto-redirect/` and copy `LLM_Auto_Redirect.php` into it.
2. Activate **LLM Auto Redirect** in WordPress. Confirm that **Redirection** is also active.
3. Open **Tools → LLM Auto Redirect**, select a provider, configure it, and save the settings.

The release ZIP contains only the plugin entry file, this README, and the license. It deliberately excludes development scripts, tests, screenshots, and legacy files.

## Providers and settings

The plugin supports Ollama, OpenAI-compatible APIs, OpenRouter, and Google Gemini. Select the provider before requesting suggestions.

For LM Studio or another OpenAI-compatible service:

- Set the base URL to the service origin plus its API prefix, usually `http://localhost:1234/v1` or `https://your-tunnel.example/v1`.
- The plugin appends `/chat/completions`; do not add that path yourself. A trailing slash is okay.
- Enter the API key or raw bearer token without a `Bearer ` prefix. LM Studio authentication must be enabled and configured with a valid token if the server is exposed through a tunnel.
- Set the model name to the exact identifier reported by the service.
- Save settings, then use **Test saved connection** to check the saved endpoint, model, and key. The check uses a short request and does not return model output to the page.
- JSON Schema mode can be enabled for compatible services; its checkbox can be turned off and saved normally.

Saved API keys are stored in the WordPress options table. The settings form never renders the saved value back into HTML. Leave the password field blank to keep a saved key, or use its clear checkbox to remove it.

## Privacy and security

When a suggestion is requested, the selected provider receives the 404 URL and the available destination-page list. Use a provider and tunnel configuration appropriate for that data. Keep authentication enabled on publicly reachable local-model endpoints.

Suggestion, connection-test, redirect-creation, and bulk actions are restricted to administrators and use WordPress AJAX nonces. Bulk processing is never triggered by front-end visits; it runs only after an administrator confirms the action.

## Development checks

The GitHub Actions workflow lints the plugin and runs the dependency-free regression tests on PHP 7.4 and PHP 8.3. The tests cover provider error handling, saved-key behavior, target validation, connection diagnostics, settings persistence, and bulk pagination.

## License

MIT. See [LICENSE.md](LICENSE.md).
