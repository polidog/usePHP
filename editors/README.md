# Editor support for `.psx`

This directory ships syntax-highlighting definitions for [PSX](../docs/PSX.md),
the TSX-like template syntax used by usePHP. Pick your editor:

| Editor      | Path                              | Install guide                          |
| ----------- | --------------------------------- | -------------------------------------- |
| Neovim / Vim | [`nvim/`](nvim/README.md)         | [`nvim/README.md`](nvim/README.md)     |
| VS Code     | [`vscode/`](vscode/README.md)     | [`vscode/README.md`](vscode/README.md) |

## What's included

All bundled definitions provide:

- File-type detection for `*.psx`
- PHP syntax (via the editor's built-in PHP grammar)
- JSX-style tags layered on top:
  - HTML elements (`<div>`, `<span>`, …)
  - PascalCase component tags (`<Counter />`)
  - Self-closing tags and fragments (`<>...</>`)
  - Attributes (literal strings and `{ ... }` PHP expressions)
  - PHP-highlighted expression embeddings inside `{ ... }`

## What's not included (yet)

- LSP / language server (type-checking, go-to-definition, completion)
- tree-sitter grammar
- PHPStan extension for PSX-aware static analysis
- Formatter integration

These are out of scope for the in-repo highlighter and will likely live
in dedicated repositories when they happen. See
[docs/PSX.md §8](../docs/PSX.md) "Phase 5 候補" for the longer-term plan.

## Reporting issues

If a `.psx` snippet is mis-highlighted, please open an issue with a
minimal reproducer and the editor + extension version.
