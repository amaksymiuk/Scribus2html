# Scribus2html
PHP class for extraction formatted text from Scribus publication.

Scribus is an open source Desktop Publishing application. I started using it many years ago, however Scribus disappointed me with one thing: the lack of a simple way to export formatted text from the final publication.

Typical lifecycle of a publication is as follows: author gives typesetter some source files, typesetter makes layout and in the process of building publication corrections and new edits are made, final PDF is passed to the printhouse. And what to do when author wants the final and cleanest version of the text for further work: preparing new and extended edition, building epub format, posting excerpts on a website? Scribus does not provide with a tool to export formatted text; only the text stripped of format may be extracted.

Ah, it was really depressing circumstance for me.

Recently I decided to definitely solve this shortcoming. This repository is a result of my efforts: single step converter from Scribus .sla file format to .html. Once you have .html, you can have everything: .odt, .doc, .rtf, ...
