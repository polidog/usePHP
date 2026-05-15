" PSX (usePHP TSX-like syntax) — syntax highlighting for Vim/Neovim
"
" PSX files are PHP source files that may contain JSX-like tags inside
" expression positions. We delegate the PHP scaffolding to the bundled
" php syntax file and inject our tag / attribute rules into the
" `phpRegion` (the area that `<?php` opens) via `containedin=`.

if exists('b:current_syntax')
  finish
endif

" Pull in the standard PHP syntax so plain PHP code is highlighted.
runtime! syntax/php.vim
unlet! b:current_syntax

syntax case match
syntax sync fromstart

" --- Contained children -------------------------------------------------
" These only match while we're inside a PSX tag region.

" Tag name: the identifier directly after `<` or `</`. Lookbehind keeps
" us from colliding with attribute names that appear later in the tag.
syntax match psxTagName
      \ /[<\/]\@1<=[a-z][a-zA-Z0-9-]*/ contained
syntax match psxComponentName
      \ /[<\/]\@1<=[A-Z][A-Za-z0-9_]*/ contained

" Attribute name: identifier preceded by whitespace (i.e. anything but
" the first token in the tag).
syntax match psxAttrName
      \ /\s\@1<=[A-Za-z_][A-Za-z0-9_-]*/ contained
      \ nextgroup=psxAttrEquals
syntax match psxAttrEquals
      \ /=/ contained
      \ nextgroup=psxAttrString,psxAttrStringSingle,psxAttrExpr

syntax region psxAttrString       start=+"+ end=+"+ contained
syntax region psxAttrStringSingle start=+'+ end=+'+ contained

" attribute={ ...php expression... } — embed the PHP cluster inside braces.
syntax region psxAttrExpr matchgroup=psxBraces
      \ start=+{+ end=+}+ contained
      \ contains=@phpClTop
      \ keepend extend

syntax match psxTagEnd       />/  contained
syntax match psxTagSelfClose +/>+ contained

" --- Top-level PSX patterns ---------------------------------------------
" These need `containedin=phpRegion` because `<?php` at the top of every
" .psx file opens `phpRegion` and it owns the rest of the buffer.

" Opening tag: `<TagName ... >` or `<TagName ... />`. `<\@<!` keeps us
" off the second `<` of `<<` (bit shift).
syntax region psxTag
      \ start=+<\@<!<[A-Za-z][A-Za-z0-9_-]*+
      \ end=+/\?>+
      \ contains=psxTagName,psxComponentName,psxAttrName,psxAttrEquals,
      \         psxAttrString,psxAttrStringSingle,psxAttrExpr,
      \         psxTagSelfClose,psxTagEnd
      \ containedin=phpRegion
      \ keepend

" Closing tag: `</tag>` / `</Component>`
syntax region psxClosingTag
      \ start=+</[A-Za-z][A-Za-z0-9_-]*+
      \ end=+>+
      \ contains=psxTagName,psxComponentName,psxTagEnd
      \ containedin=phpRegion
      \ keepend

" Fragment shorthand `<>` / `</>`
syntax match psxFragment /<\/\?>/ containedin=phpRegion

" --- Highlight links ----------------------------------------------------
highlight default link psxTag              Identifier
highlight default link psxClosingTag       Identifier
highlight default link psxTagName          Statement
highlight default link psxComponentName    Type
highlight default link psxAttrName         Identifier
highlight default link psxAttrEquals       Operator
highlight default link psxAttrString       String
highlight default link psxAttrStringSingle String
highlight default link psxTagEnd           Delimiter
highlight default link psxTagSelfClose     Delimiter
highlight default link psxFragment         Delimiter
highlight default link psxBraces           Special

let b:current_syntax = 'psx'
