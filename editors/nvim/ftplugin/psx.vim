" PSX (usePHP) — file type plugin
if exists('b:did_ftplugin')
  finish
endif
let b:did_ftplugin = 1

setlocal commentstring=//\ %s
setlocal suffixesadd=.psx,.php
setlocal iskeyword+=$

let b:undo_ftplugin = 'setlocal commentstring< suffixesadd< iskeyword<'
