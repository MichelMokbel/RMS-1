# .agents Directory

This directory contains agent configuration and skills for OpenAI Codex CLI.

## Structure

```
.agents/
  config.toml     # Main configuration file
  skills/         # Skill definitions
    skill-name/
      SKILL.md    # Skill instructions
      scripts/    # Optional scripts
      docs/       # Optional documentation
  README.md       # This file
```

## Configuration

The `config.toml` file controls:
- Model selection
- Approval policies
- Sandbox modes
- MCP server connections
- Skills configuration

## Skills

Skills are invoked using `$skill-name` syntax. Each skill has:
- YAML frontmatter with metadata
- Trigger and skip conditions
- Commands and examples

### Engineering workflow skill pack

The following skills are vendored from
[`jsmastery-pro/skills`](https://github.com/jsmastery-pro/skills) at commit
`43b69e44c9ca905fe3a3418ccdf4102255e20d40`:

- `$scope`
- `$audit`
- `$architect`
- `$develop`
- `$check`
- `$test`
- `$document`
- `$sync`
- `$debug`

See `skills/jsmastery-pro-skills.SOURCE.md` for provenance and update guidance.
The upstream MIT license is preserved in
`skills/jsmastery-pro-skills.LICENSE`.

## Documentation

- Main instructions: `AGENTS.md` (project root)
- Local overrides: `.codex/AGENTS.override.md` (gitignored)
- Claude Flow: https://github.com/ruvnet/claude-flow
