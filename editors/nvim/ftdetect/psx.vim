" PSX (usePHP TSX-like syntax) — filetype detection
augroup psxDetect
  autocmd!
  autocmd BufRead,BufNewFile *.psx setfiletype psx
augroup END
