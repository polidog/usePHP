# PSX syntax highlighting for Vim / Neovim

Adds filetype detection and syntax highlighting for `.psx` files (the
TSX-like template syntax used by [usePHP](../../README.md)).

## What it does

- Detects `*.psx` as `filetype=psx`
- Pulls in the bundled `syntax/php.vim` so PHP gets full highlighting
- Layers JSX-style rules on top: tags, components, attributes,
  `{ ... }` PHP expressions, fragments

This is a pure Vim-script syntax — no Lua, no external dependencies,
works on both Vim and Neovim.

## Install

The simplest path is to point your plugin manager at this repo and tell
it to use `editors/nvim` as its runtime root.

### lazy.nvim (Neovim)

```lua
{
  'polidog/usePHP',
  ft = 'psx',
  config = function(plugin)
    vim.opt.runtimepath:append(plugin.dir .. '/editors/nvim')
    vim.cmd('runtime! ftdetect/psx.vim')
  end,
}
```

### packer.nvim

```lua
use({
  'polidog/usePHP',
  rtp = 'editors/nvim',
})
```

### vim-plug

`vim-plug` does not support sub-directory runtime paths natively. Use
the manual install below, or pin the plugin and add the subdir to
`runtimepath` yourself:

```vim
Plug 'polidog/usePHP'

" After plug#end()
let &runtimepath .= ',' . stdpath('data') . '/plugged/usePHP/editors/nvim'
runtime! ftdetect/psx.vim
```

### Native packages (Vim 8+ / Neovim)

```bash
git clone https://github.com/polidog/usePHP \
  ~/.local/share/nvim/site/pack/psx/start/usePHP
# Then either symlink the sub-tree:
ln -s ~/.local/share/nvim/site/pack/psx/start/usePHP/editors/nvim/ftdetect \
      ~/.config/nvim/ftdetect-psx
# …or copy the three files below into your config (see "Manual").
```

### Manual

Just three files — drop them into your nvim/vim config:

```bash
# Neovim
mkdir -p ~/.config/nvim/{ftdetect,syntax,ftplugin}
cp editors/nvim/ftdetect/psx.vim ~/.config/nvim/ftdetect/
cp editors/nvim/syntax/psx.vim   ~/.config/nvim/syntax/
cp editors/nvim/ftplugin/psx.vim ~/.config/nvim/ftplugin/

# Vim
mkdir -p ~/.vim/{ftdetect,syntax,ftplugin}
cp editors/nvim/ftdetect/psx.vim ~/.vim/ftdetect/
cp editors/nvim/syntax/psx.vim   ~/.vim/syntax/
cp editors/nvim/ftplugin/psx.vim ~/.vim/ftplugin/
```

Or symlink for live updates while iterating on the syntax file:

```bash
ln -s "$(pwd)/editors/nvim/ftdetect/psx.vim"  ~/.config/nvim/ftdetect/psx.vim
ln -s "$(pwd)/editors/nvim/syntax/psx.vim"    ~/.config/nvim/syntax/psx.vim
ln -s "$(pwd)/editors/nvim/ftplugin/psx.vim"  ~/.config/nvim/ftplugin/psx.vim
```

## Verify

Open any `.psx` file and check:

```vim
:set filetype?
" -> filetype=psx

:syntax list psxTagName
" -> should print a syntax definition (not "No syntax items defined")
```

If you see `filetype=` empty, run `:filetype on` and reload the buffer.

## Notes & limitations

- PSX is a thin layer over PHP. The bundled `php.vim` does most of the
  work; we only add JSX-style tag/attribute rules on top.
- Like all regex-based syntaxes, the matcher is context-free. In rare
  cases code like `$a < $b` may be mis-highlighted. PSX only allows
  tags at expression positions, but Vim's syntax engine doesn't track
  that — file an issue with a reproducer if it bites you.
- A tree-sitter grammar would resolve this but is out of scope for the
  initial drop.
