# PSX syntax highlighting for VS Code

Adds syntax highlighting and basic editor configuration for `.psx`
files (the TSX-like template syntax used by [usePHP](../../README.md)).

## What it does

- Registers `psx` as a language and associates `*.psx` with it
- Highlights PHP via the built-in `source.php` grammar
- Adds JSX-style rules on top: tags, components, attributes,
  `{ ... }` PHP expressions, fragments
- Provides bracket matching, auto-closing pairs and comment toggles

## Install

The extension is not on the Marketplace yet. Install one of three ways:

### Option A — Build a `.vsix` and install (recommended)

Requires Node.js. Install [`vsce`](https://github.com/microsoft/vscode-vsce) once:

```bash
npm install -g @vscode/vsce
```

Then from this directory:

```bash
cd editors/vscode
vsce package          # produces vscode-psx-0.1.0.vsix
code --install-extension vscode-psx-0.1.0.vsix
```

`vsce` will warn about a missing `LICENSE` and `repository`; that's
fine — `--allow-missing-repository` and `--no-license` can silence it
if it ever errors out.

### Option B — Symlink into your extensions folder

The fastest dev loop. The extension folder location depends on your OS:

| OS      | Path                                             |
| ------- | ------------------------------------------------ |
| macOS   | `~/.vscode/extensions/`                          |
| Linux   | `~/.vscode/extensions/`                          |
| Windows | `%USERPROFILE%\.vscode\extensions\`              |

VSCodium / Cursor / other forks store extensions under
`~/.vscode-oss`, `~/.cursor`, etc. — adjust accordingly.

```bash
# macOS / Linux
ln -s "$(pwd)/editors/vscode" ~/.vscode/extensions/polidog.vscode-psx-0.1.0
```

```powershell
# Windows (PowerShell, run as admin)
New-Item -ItemType SymbolicLink `
  -Path "$env:USERPROFILE\.vscode\extensions\polidog.vscode-psx-0.1.0" `
  -Target (Resolve-Path .\editors\vscode)
```

Reload VS Code (`Developer: Reload Window`). Symlinks pick up edits to
the grammar without re-packaging.

### Option C — Copy

If symlinks aren't an option, just copy the folder:

```bash
cp -R editors/vscode ~/.vscode/extensions/polidog.vscode-psx-0.1.0
```

You'll need to re-copy after each pull.

## Verify

Open any `.psx` file. The status bar at the bottom-right should read
**PSX**. If it shows **PHP** or **Plain Text**, click the language
selector and pick PSX, or check that the extension is loaded under
`Extensions: Show Installed Extensions`.

To inspect what's coloring a token: open the command palette →
`Developer: Inspect Editor Tokens and Scopes`. Tokens under PSX scopes
will show `source.psx`, `entity.name.tag.psx`, etc.

## Notes & limitations

- The grammar is a TextMate grammar layered on top of `source.php`.
- It is intentionally permissive: any `<TagName>` / `<tagname>` at any
  position is treated as a PSX tag, even though PSX itself only allows
  them at expression positions. Code like `$a < $b` may be
  mis-highlighted in rare cases — open an issue if you hit one.
- An LSP or tree-sitter grammar would solve this properly, and is on
  the roadmap as a separate project.
