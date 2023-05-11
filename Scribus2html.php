#!/usr/bin/php
<?php
#!/usr/bin/php <- she-bang for my Ubuntu
#!/c/PHP/php.exe <- she-bang for my "Git-Bash" console under Windows
/**
 * PHP class for extraction formatted text from Scribus publication
 * Copyright (c) Aleksander Maksymiuk, info@setpro.net.pl
 * Last change: 2023-05-11, 09:25
 * License: Creative Commons, Attribution-ShareAlike
 *
 * README
 * ------
 * This script requires PHP with XML support (i.e. xml, xmlreader modules
 * installed).
 * This script is a complete command line tool - you only need to adjust its
 * first line with correct interpreter and give the file executable permission.
 * Run it in terminal as
 *   ./Scribus2html.php <scribus-file-name>.sla
*/

class Scribus2html {

    // how to generate html file
    protected $conf = array(
        // Scribus items to process (text frames are always processed)
        'frame-image' => 0,
        'frame-latex' => 0,
        'style-sheet' => 1,
        // paragraph, text-chunk formatting (1/0 - include/exclude)
        'font-family' => 0,
        'font-size' => 1,
        'text-align' => 1,
        'line-height' => 1,
        'text-indent' => 1,
        'margin-*' => 1,
        // character attributes (1/0 - include/exclude)
        'attr-bold' => 1,
        'attr-italic' => 1,
        'attr-underline' => 1,
        'attr-strike' => 1,
        'attr-superscript' => 1,
        'attr-subscript' => 1,
        'attr-allcaps' => 1,
        'attr-smallcaps' => 1,
        'attr-shadow' => 1,
        // character removal/conversion
        'soft-hyph' => 0, // 1/0 - preserve/remove
        'hard-space' => 1, // 1/0 - preserve/convert-to-ordinary
        'hard-hyph' => 1, // 1/0 - preserve/convert-to-ordinary
    );

    protected $sla = null;
    protected $sla_parts = array();

    protected $xml = null;

    protected $meta = array();
    protected $data = array();
    protected $stylesheet = array();

    protected $stat = array(
        'styles' => 0,
        'rframes' => 0,
        'iframes' => 0,
        'tframes' => 0,
        'paragraphs' => 0,
    );

    public function __construct($sla_name) {
        if (!empty($sla_name) && file_exists($sla_name)) {
            $this->sla = $sla_name;
            $this->sla_parts = pathinfo($this->sla);
            $this->xml = new XMLReader();
            $this->xml->open($this->sla, 'UTF-8', LIBXML_NOBLANKS);
            // add to config some useful constants
            $this->conf['scribus-soft-hyph'] = iconv('CP1250', 'UTF-8', chr(173) . chr(173));
        }
    }

    public function run() {
        if (!empty($this->xml) && ($this->xml instanceof XMLReader)) {
            echo 'Parsing ' . $this->sla . PHP_EOL;
            // iterate over Scribus main items
            while ($this->xml->read()) {
                if ($this->xml->nodeType != XMLReader::ELEMENT) {
                    continue;
                }
                // check version: at least 1.4.6 required
                if (($this->xml->name == 'SCRIBUSUTF8NEW')) {
                    $ver = explode('.', $this->xml->getAttribute('Version'));
                    if (($ver[0] < 1) || ($ver[1] < 4) || ($ver[2] < 6)) {
                        echo 'Scribus file version too old (' . implode('.', $ver) . ')' . PHP_EOL;
                        echo '... save your file as 1.4.6 or higher in order to use this tool' . PHP_EOL;
                        echo 'Done' . PHP_EOL;
                        exit(1);
                    }
                }
                if (($this->xml->name == 'DOCUMENT')) {
                    $this->meta['author'] = $this->xml->getAttribute('AUTHOR');
                    $this->meta['title'] = $this->xml->getAttribute('TITLE');
                    $this->meta['comment'] = $this->xml->getAttribute('COMMENT');
                }
                // parse styles
                if (($this->xml->name == 'STYLE') && $this->conf['style-sheet']) {
                    $this->processStyle();
                }
                if ($this->xml->name == 'PAGEOBJECT') {
                    $type = intval($this->xml->getAttribute('PTYPE'));
                    switch ($type) {
                        case 4: // text frame
                            $this->processTFrame();
                            break;
                        case 2: // image frame
                            if ($this->conf['frame-image']) {
                                $this->processIFrame();
                            }
                            break;
                        case 9: // render (LaTeX) frame
                            if ($this->conf['frame-latex']) {
                                $this->processRFrame();
                            }
                            break;
                        default:
                    }
                }
            }
            $this->xml->close();
            echo '... ' . $this->stat['styles'] . ' global styles' . PHP_EOL;
            echo '... ' . $this->stat['rframes'] . ' render (LaTeX) frames' . PHP_EOL;
            echo '... ' . $this->stat['iframes'] . ' image frames' . PHP_EOL;
            echo '... ' . $this->stat['tframes'] . ' text frames' . PHP_EOL;
            echo '... ' . $this->stat['paragraphs'] . ' paragraphs' . PHP_EOL;
            $this->save();
        }
    }

    protected function processStyle() {
        $this->stat['styles']++;
        $class = $this->processClass($this->xml->getAttribute('NAME'));
        $this->stylesheet[$class] = array_merge(
            $this->processFontFamily($this->xml->getAttribute('FONT')),
            $this->processFontSize($this->xml->getAttribute('FONTSIZE')),
            $this->processTextAlign($this->xml->getAttribute('ALIGN')),
            $this->processLineHeight($this->xml->getAttribute('LINESP')),
            $this->processTextIndent($this->xml->getAttribute('FIRST')),
            $this->processMarginTop($this->xml->getAttribute('VOR')),
            $this->processMarginRight($this->xml->getAttribute('RMARGIN')),
            $this->processMarginBottom($this->xml->getAttribute('NACH')),
            $this->processMarginLeft($this->xml->getAttribute('INDENT'))
        );
    }

    protected function processTFrame() {
        $this->stat['tframes']++;
        // get page the frame belongs to
        $page = intval($this->xml->getAttribute('OwnPage'));
        // start subreader for processing frame items
        $frm = new XMLReader();
        $frm->XML($this->xml->readOuterXml(), 'UTF-8', LIBXML_NOBLANKS);
        $paragraphs = array();
        $i = 0;
        $paragraphs[$i] = '';
        while ($frm->read()) {
            if ($frm->nodeType != XMLReader::ELEMENT) {
                continue;
            }
            switch ($frm->name) {
                case 'ITEXT':
                    // get chunk of text
                    $chunk = $frm->getAttribute('CH');
                    // remove soft hyphens
                    if (!$this->conf['soft-hyph']) {
                        $chunk = str_replace($this->conf['scribus-soft-hyph'], '', $chunk);
                    }
                    $style = array_merge(
                        $this->processFontFamily($frm->getAttribute('FONT')),
                        $this->processFontSize($frm->getAttribute('FONTSIZE')),
                        $this->processTextFeatures($frm->getAttribute('FEATURES'))
                    );
                    $tag_open = '';
                    $tag_close = '';
                    if (!empty($style)) {
                        $tag_open = '<span ' . $this->buildStyleInline($style) . '>';
                        $tag_close = '</span>';
                    }
                    $chunk = htmlspecialchars($chunk);
                    // convert soft hyphen to html entity
                    if ($this->conf['soft-hyph']) {
                        $chunk = str_replace($this->conf['scribus-soft-hyph'], '&shy;', $chunk);
                    }
                    // glue text to the current paragraph
                    $paragraphs[$i] .= $tag_open . $chunk . $tag_close;
                    break;
                case 'para':
                case 'trail':
                    $this->stat['paragraphs']++;
                    // check paragraph formatting
                    $class = $this->processClass($frm->getAttribute('PARENT'));
                    $style = array_merge(
                        $this->processTextAlign($frm->getAttribute('ALIGN')),
                        $this->processTextIndent($frm->getAttribute('FIRST')),
                        $this->processMarginLeft($frm->getAttribute('INDENT')),
                        $this->processLineHeight($frm->getAttribute('LINESP')),
                        $this->processLineHeightMode($frm->getAttribute('LINESPMode'))
                    );
                    $attrs = array();
                    if (!empty($class) && $this->conf['style-sheet']) {
                        $attrs[] = 'class="' . $class . '"';
                    }
                    if (!empty($style)) {
                        $attrs[] = $this->buildStyleInline($style);
                    }
                    $tag_open = '<p>';
                    $tag_close = '</p>';
                    if (!empty($attrs)) {
                        $tag_open = '<p ' . implode(' ', $attrs) . '>';
                    }
                    // close current paragraph...
                    $paragraphs[$i] = $tag_open . $paragraphs[$i] . $tag_close;
                    // ... and start new one
                    if ($frm->name == 'para') {
                        $paragraphs[++$i] = '';
                    }
                    break;
                case 'breakline':
                    $paragraphs[$i] .= '<br>';
                    break;
                case 'nbspace':
                    $paragraphs[$i] .= ($this->conf['hard-space'] ? '&nbsp;' : ' ');
                    break;
                case 'nbhyphen':
                    $paragraphs[$i] .= ($this->conf['hard-hyph'] ? '&#8209;' : '-');
                    break;
                case 'tab':
                    // ah, big simplification here, since tab is an ordinary whitespace in html
                    $paragraphs[$i] .= ' ';
                    break;
                default:
            }
        }
        unset($frm);
        // keep collected text
        $this->data[$page]['tframes'][] = $paragraphs;
    }

    protected function processIFrame() {
        $this->stat['iframes']++;
        // get page the frame belongs to
        $page = intval($this->xml->getAttribute('OwnPage'));
        // get image file name
        $image = $this->xml->getAttribute('PFILE');
        $this->data[$page]['iframes'][] = '<pre>[IMAGE[' . $image . ']]</pre>';
    }

    protected function processRFrame() {
        $this->stat['rframes']++;
        // get page the frame belongs to
        $page = intval($this->xml->getAttribute('OwnPage'));
        // start subreader for processing frame items
        $frm = new XMLReader();
        $frm->XML($this->xml->readOuterXml(), 'UTF-8', LIBXML_NOBLANKS);
        $code = '';
        while ($frm->read()) {
            if ($frm->nodeType != XMLReader::ELEMENT) {
                continue;
            }
            if ($frm->name == 'LATEX') {
                // get source code
                $code = $frm->readString();
                break;
            }
        }
        unset($frm);
        $this->data[$page]['rframes'][] = '<pre>[LATEX[' . $code . ']]</pre>';
    }

    protected function processFontFamily($name) {
        $style = array();
        if (isset($name)) {
            $name = preg_replace('#\s*regular\s*#i', '', $name);
            $style['font-weight'] = 'normal';
            $style['font-style'] = 'normal';
            if (preg_match('#bold#i', $name)) {
                $name = preg_replace('#\s*bold\s*#i', '', $name);
                if ($this->conf['attr-bold']) {
                    $style['font-weight'] = 'bold';
                }
            }
            if (preg_match('#italic#i', $name)) {
                $name = preg_replace('#\s*italic\s*#i', '', $name);
                if ($this->conf['attr-italic']) {
                    $style['font-style'] = 'italic';
                }
            }
            if (preg_match('#light#i', $name)) {
                $name = preg_replace('#\s*light\s*#i', '', $name);
                $style['font-weight'] = 'lighter';
            }
            if (preg_match('#condensed#i', $name)) {
                $name = preg_replace('#\s*condensed\s*#i', '', $name);
                $style['font-stretch'] = 'condensed';
            }
            // add typeface name
            if ($this->conf['font-family']) {
                $style['font-family'] = "'" . $name . "'";
            }
        }
        return $style;
    }

    protected function processFontSize($size) {
        $style = array();
        if ($this->conf['font-size'] && isset($size)) {
            $style['font-size'] = $size . 'pt';
        }
        return $style;
    }

    protected function processClass($name) {
        // adjust class name for html: exclude spaces, digit in front
        $name = str_replace(' ', '_', $name);
        $name = preg_replace('#^(\d)#', '_$1', $name);
        return $name;
    }

    protected function processTextFeatures($features) {
        $style = array();
        if (isset($features)) {
            if ($this->conf['attr-underline'] && preg_match('#underline#i', $features)) {
                $style['text-decoration'][] = 'underline';
            }
            if ($this->conf['attr-strike'] && preg_match('#strike#i', $features)) {
                $style['text-decoration'][] = 'line-through';
            }
            if ($this->conf['attr-superscript'] && preg_match('#superscript#i', $features)) {
                $style['vertical-align'] = 'super';
                $style['font-size'] = 'smaller';
            }
            if ($this->conf['attr-subscript'] && preg_match('#subscript#i', $features)) {
                $style['vertical-align'] = 'sub';
                $style['font-size'] = 'smaller';
            }
            if ($this->conf['attr-allcaps'] && preg_match('#(?<!sm)allcaps#i', $features)) {
                $style['text-transform'][] = 'uppercase';
            }
            if ($this->conf['attr-smallcaps'] && preg_match('#smallcaps#i', $features)) {
                $style['font-variant'][] = 'small-caps';
            }
            if ($this->conf['attr-shadow'] && preg_match('#shadowed#i', $features)) {
                $style['text-shadow'][] = '2px 2px 1px #808080';
            }
        }
        return $style;
    }

    protected function processTextAlign($align) {
        $style = array();
        if ($this->conf['text-align'] && isset($align)) {
            switch ($align) {
                case 0:
                    $style['text-align'] = 'left';
                    break;
                case 1:
                    $style['text-align'] = 'center';
                    break;
                case 2:
                    $style['text-align'] = 'right';
                    break;
                case 3:
                    $style['text-align'] = 'justify';
                    break;
                default:
            }
        }
        return $style;
    }

    protected function processLineHeight($height) {
        $style = array();
        if ($this->conf['line-height'] && isset($height)) {
            $style['line-height'] = $height . 'pt';
        }
        return $style;
    }

    protected function processLineHeightMode($mode) {
        $style = array();
        if ($this->conf['line-height'] && isset($mode) && ($mode == 1)) {
            $style['line-height'] = 'auto';
        }
        return $style;
    }

    protected function processTextIndent($first) {
        $style = array();
        if ($this->conf['text-indent'] && isset($first)) {
            $style['text-indent'] = number_format($first, 2, '.', '') . 'pt';
        }
        return $style;
    }

    protected function processMarginTop($top) {
        $style = array();
        if ($this->conf['margin-*'] && isset($top)) {
            $style['margin-top'] = number_format($top, 2, '.', '') . 'pt';
        }
        return $style;
    }

    protected function processMarginRight($right) {
        $style = array();
        if ($this->conf['margin-*'] && isset($right)) {
            $style['margin-right'] = number_format($right, 2, '.', '') . 'pt';
        }
        return $style;
    }

    protected function processMarginBottom($bottom) {
        $style = array();
        if ($this->conf['margin-*'] && isset($bottom)) {
            $style['margin-bottom'] = number_format($bottom, 2, '.', '') . 'pt';
        }
        return $style;
    }

    protected function processMarginLeft($left) {
        $style = array();
        if ($this->conf['margin-*'] && isset($left)) {
            $style['margin-left'] = number_format($left, 2, '.', '') . 'pt';
        }
        return $style;
    }

    protected function buildStyleInline($style) {
        $retval = '';
        if (!empty($style)) {
            $inline = '';
            foreach ($style as $prop => $val) {
                $inline .= $prop . ': ' . (is_array($val) ? implode(' ', $val) : $val) . '; ';
            }
            $retval = 'style="' . trim($inline) . '"';
        }
        return $retval;
    }

    protected function buildHtmlOpen() {
        $tab = '    ';
        $styles = '';
        if (!empty($this->stylesheet)) {
            foreach ($this->stylesheet as $name => $def) {
                $styles .= $tab . $tab . '.' . $name . ' {' . PHP_EOL;
                foreach ($def as $prop => $val) {
                    $styles .= $tab . $tab . $tab . $prop . ': ' . (is_array($val) ? implode(' ', $val) : $val) . ';' . PHP_EOL;
                }
                $styles .= $tab . $tab . '}' . PHP_EOL;
            }
        }
        return '<!DOCTYPE html>' . PHP_EOL .
        '<html>' . PHP_EOL .
            '<head>' . PHP_EOL .
            $tab . '<meta charset="UTF-8">' . PHP_EOL .
            $tab . '<title>' . (empty($this->meta['title']) ?
                htmlspecialchars($this->sla_parts['filename'] . '.html') :
                htmlspecialchars($this->meta['title'])
            ) . '</title>' . PHP_EOL .
            $tab . '<meta name="author" content="' . htmlspecialchars($this->meta['author']) . '">' . PHP_EOL .
            $tab . '<meta name="description" content="' . (empty($this->meta['comment']) ?
                '' :
                htmlspecialchars($this->meta['comment']) . ' '
            ) . 'Formatted text extracted from ' . htmlspecialchars($this->sla_parts['basename']) . ' by Scribus2html.php">' . PHP_EOL .
            $tab . '<meta name="keywords" content="scribus2html">' . PHP_EOL .
            $tab . '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . PHP_EOL .
            ($this->conf['style-sheet'] ?
                $tab . '<style>' . PHP_EOL . $styles . $tab . '</style>' . PHP_EOL :
                ''
            ) .
            '</head>' . PHP_EOL .
            '<body>' .
        PHP_EOL;
    }

    protected function buildHtmlClose() {
        return '</body>' . PHP_EOL .
        '</html>';
    }

    protected function save() {
        // sort data collection by page numbers
        ksort($this->data);
        // build plain html
        $html = $this->buildHtmlOpen();
        foreach ($this->data as $page => $frames) {
            // page info as a comment
            $html .= '<!-- page ' . $page . ' -->' . PHP_EOL;
            // list images first...
            if (isset($frames['iframes'])) {
                foreach ($frames['iframes'] as $image) {
                    $html .= $image . PHP_EOL;
                }
            }
            // ... then LaTeX items...
            if (isset($frames['rframes'])) {
                foreach ($frames['rframes'] as $code) {
                    $html .= $code . PHP_EOL;
                }
            }
            // ... and finally text
            if (isset($frames['tframes'])) {
                foreach ($frames['tframes'] as $paragraphs) {
                    foreach ($paragraphs as $para) {
                        $html .= $para . PHP_EOL;
                    }
                }
            }
        }
        $html .= $this->buildHtmlClose();
        $output = $this->sla_parts['dirname'] . '/' . $this->sla_parts['filename'] . '.html';
        echo 'Writing ' . $output . PHP_EOL;
        if (($size = file_put_contents($output, $html)) !== false) {
            echo '... ' . $size . ' bytes' . PHP_EOL;
        }
        echo 'Done' . PHP_EOL;
    }

}

###### run the above class as a command line tool #############################

    if (isset($argv[1]) && file_exists($argv[1])) {
        $parts = pathinfo($argv[1]);
        if (strtolower($parts['extension']) == 'sla') {
            $app = new Scribus2html($argv[1]);
            $app->run();
            unset($app);
        }
    }
