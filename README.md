# Scribus2html

PHP class for conversion Scribus publication to HTML format.

## Short history

Scribus is an open source Desktop Publishing application. I started using it
many years ago, however Scribus disappointed me with one thing: the lack of
a simple way to export formatted text from the final publication.

Typical lifecycle of a publication is as follows: author gives typesetter some
source files, typesetter makes layout and in the process of building
publication corrections and new edits are made, final PDF is passed to the
printhouse. And what to do when author wants the final and cleanest version of
the text for further work: preparing new and extended edition, building epub
format, posting excerpts on a website? Scribus does not provide with a tool to
export formatted text; only the text stripped of format may be extracted.

Ah, it was really depressing circumstance for me.

In May, 2023, I decided to definitely solve this shortcoming. This repository
is a result of my efforts: single step converter from Scribus `.sla` file
format to `.html`. Once you have `.html`, you can have everything: `.odt`,
`.doc`, `.rtf`, ...

## Usage

Get files `img2jpg.php`, `Scribus2html.ini`, and `Scribus2html.php` to your
local system, give the last one executable permission. Maybe you will also need
to adjust first line of `Scribus2html.php` with correct path to PHP
interpreter, however the default `#!/usr/bin/php` should be fine for most Linux
distributions. Run terminal, change dir to the location of scripts, and execute
the command:

```
./Scribus2html.php path/to/your_scribus_file.sla
```

### How to use this script under Windows

This is my personal recipe. Under Windows I use „Git Bash” console that gives
me Linux-like feel, look, and behavior. The only thing that needs to be changed
in `Scribus2html.php` script is its first line: `#!/c/PHP/php.exe`, where
`C:\PHP` is my directory with PHP interpreter.

## Possible problems

`Scribus2html.php` script requires PHP with XML support. PHP distributions for
Windows have XML support enabled by default, while distributions for Linux may
not have. If you run the above command in Linux terminal and see error like
„XMLReader class not found”, it means that your PHP lacks modules supporting
XML (xml, xmlreader), you need to add those modules manually.

The script also takes advantage of ImageMagick application in order to convert
images used in Scribus publication into format understandable by web browser.
If you want to generate final `.html` file with images, install ImageMagick in
your local system.

## Changing run options

Open, read, and edit the file `Scribus2html.ini`