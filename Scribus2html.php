#!/usr/bin/php
<?php
/**
 * PHP class for conversion Scribus publication to HTML format.
 * Aleksander Maksymiuk, <am@setpro.pl>
 * 
 * Quick start
 * ----------------------------------------------------------------------------
 * This script requires PHP with XML support.
 * The script is a complete command line tool; verify that first line contains
 * correct path to PHP interpreter and give the file executable permission.
 * Run it within terminal as
 *   ./Scribus2html.php path/to/your_scribus_file.sla
 * ----------------------------------------------------------------------------
*/

class Scribus2html {

    // how to generate html file
    protected $conf = [
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
        'tab-as-comment' => 0, // 1/0 - convert-to-table/ignore
        // image parameters
        'img-cnv-ext' => 'jpg',
        'img-dir-rel' => 'images(Scribus2html)',
        'img-max-width' => '640px',
        'img-max-height' => '512px',
        // styling some html tags
        'html-tag-style' => [
            'pre' => [
                'font-weight' => 'bold',
                'margin' => '16px 0 16px 0',
                'padding' => '16px 16px 16px 16px',
                'border' => 'solid 1px #c8c8c8',
            ],
            'img' => [
                'display' => 'block',
                'margin' => '16px 0 16px 0',
                'padding' => '16px 16px 16px 16px',
                'border' => 'solid 1px #c8c8c8',
            ],
        ],
    ];

    protected $sla = null;
    protected $sla_parts = [];

    protected $xml = null;

    protected $meta = [];
    protected $data = [];
    protected $stylesheet = [];

    protected $stat = [
        'styles' => 0,
        'rframes' => 0,
        'iframes' => 0,
        'tframes' => 0,
        'paragraphs' => 0,
    ];

    public function __construct($sla_name) {
        if (!empty($sla_name) && file_exists($sla_name)) {
            echo 'Initializing' . PHP_EOL;
            $this->sla = $sla_name;
            $this->sla_parts = pathinfo($this->sla);
            $this->xml = new XMLReader();
            $this->xml->open($this->sla, 'UTF-8', LIBXML_NOBLANKS);
            // parse user's options
            $this->processConfig();
            // add to config some useful constants
            $this->conf['scribus-soft-hyph'] = iconv('CP1250', 'UTF-8', chr(173) . chr(173));
            $this->conf['html-tab-fake'] = '<!--tab--> ';
            // init image parameters
            if ($this->conf['frame-image']) {
                require_once('img2jpg.php');
                // check whether we can use ImageMagick or no
                $this->conf['img-magick'] = img2jpg::isAvail();
                // directory for web version of images
                $this->conf['img-dir-full'] = $this->sla_parts['dirname'] . '/' . $this->conf['img-dir-rel'];
                if (!file_exists($this->conf['img-dir-full']) || !is_dir($this->conf['img-dir-full'])) {
                    if (mkdir($this->conf['img-dir-full'])) {
                        echo '... ' . $this->conf['img-dir-full'] . PHP_EOL;
                    }
                }
            }
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
                // check file version: at least 1.4.6 required
                if ($this->xml->name == 'SCRIBUSUTF8NEW') {
                    $ver = explode('.', $this->xml->getAttribute('Version'));
                    if (($ver[0] < 1) || ($ver[1] < 4) || ($ver[2] < 6)) {
                        echo 'Scribus file version too old (' . implode('.', $ver) . ')' . PHP_EOL;
                        echo '... save your file as 1.4.6 or higher in order to use this tool' . PHP_EOL;
                        echo 'Done' . PHP_EOL;
                        exit(1);
                    }
                }
                if ($this->xml->name == 'DOCUMENT') {
                    $this->meta['author'] = $this->xml->getAttribute('AUTHOR');
                    $this->meta['title'] = $this->xml->getAttribute('TITLE');
                    $this->meta['comment'] = $this->xml->getAttribute('COMMENT');
                }
                // parse styles
                if ($this->xml->name == 'STYLE') {
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
        $paragraphs = [];
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
                    $attrs = [];
                    if (!empty($class) && $this->conf['style-sheet']) {
                        // add style as a class attribute
                        $attrs[] = 'class="' . $class . '"';
                    } elseif (!empty($class) && !$this->conf['style-sheet']) {
                        // merge class with inline style
                        $style = array_merge($this->stylesheet[$class], $style);
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
                    // tab is an ordinary whitespace in html: mark it with a comment for possible further processing
                    $paragraphs[$i] .= $this->conf['html-tab-fake'];
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
        if ($this->conf['img-magick']) {
            $parts = pathinfo($image);
            echo '... ' . $parts['basename'] . PHP_EOL;
            // convert and normalize image
            $cnv = new img2jpg(
                $this->sla_parts['dirname'] . '/' . $image,
                $this->conf['img-dir-full'] . '/' . $parts['filename'] . '.' . $this->conf['img-cnv-ext'],
                1024,
                false
            );
            if ($cnv->run()) {
                // add full-fledged image link
                $style = [
                    'max-width' => $this->conf['img-max-width'],
                    'max-height' => $this->conf['img-max-height'],
                ];
                if (isset($this->conf['html-tag-style']['img']) && !$this->conf['style-sheet']) {
                    $style = array_merge($this->conf['html-tag-style']['img'], $style);
                }
                $this->data[$page]['iframes'][] = '<img src="' . $this->conf['img-dir-rel'] . '/' . $parts['filename'] . '.' .
                    $this->conf['img-cnv-ext'] . '" alt="' . $parts['filename'] . '" title="' . $parts['filename'] .
                    '" ' . $this->buildStyleInline($style) .
                '>';
            } else {
                // something went wrong - add image info alone
                $style = [];
                if (isset($this->conf['html-tag-style']['pre']) && !$this->conf['style-sheet']) {
                    $style = $this->conf['html-tag-style']['pre'];
                }
                $this->data[$page]['iframes'][] = '<pre' . (!empty($style) ? ' ' . $this->buildStyleInline($style) : '') . '>[IMAGE[' . $image . ']]</pre>';
            }
            unset($cnv);
        } else {
            // add image info alone
            $style = [];
            if (isset($this->conf['html-tag-style']['pre']) && !$this->conf['style-sheet']) {
                $style = $this->conf['html-tag-style']['pre'];
            }
            $this->data[$page]['iframes'][] = '<pre' . (!empty($style) ? ' ' . $this->buildStyleInline($style) : '') . '>[IMAGE[' . $image . ']]</pre>';
        }
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
        $style = [];
        if (isset($this->conf['html-tag-style']['pre']) && !$this->conf['style-sheet']) {
            $style = $this->conf['html-tag-style']['pre'];
        }
        $this->data[$page]['rframes'][] = '<pre' . (!empty($style) ? ' ' . $this->buildStyleInline($style) : '') . '>[LATEX[' . $code . ']]</pre>';
    }

    protected function processFontFamily($name) {
        $style = [];
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
        $style = [];
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
        $style = [];
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
        $style = [];
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
        $style = [];
        if ($this->conf['line-height'] && isset($height)) {
            $style['line-height'] = $height . 'pt';
        }
        return $style;
    }

    protected function processLineHeightMode($mode) {
        $style = [];
        if ($this->conf['line-height'] && isset($mode) && ($mode == 1)) {
            $style['line-height'] = 'auto';
        }
        return $style;
    }

    protected function processTextIndent($first) {
        $style = [];
        if ($this->conf['text-indent'] && isset($first)) {
            $style['text-indent'] = number_format($first, 2, '.', '') . 'pt';
        }
        return $style;
    }

    protected function processMarginTop($top) {
        $style = [];
        if ($this->conf['margin-*'] && isset($top)) {
            $style['margin-top'] = number_format($top, 2, '.', '') . 'pt';
        }
        return $style;
    }

    protected function processMarginRight($right) {
        $style = [];
        if ($this->conf['margin-*'] && isset($right)) {
            $style['margin-right'] = number_format($right, 2, '.', '') . 'pt';
        }
        return $style;
    }

    protected function processMarginBottom($bottom) {
        $style = [];
        if ($this->conf['margin-*'] && isset($bottom)) {
            $style['margin-bottom'] = number_format($bottom, 2, '.', '') . 'pt';
        }
        return $style;
    }

    protected function processMarginLeft($left) {
        $style = [];
        if ($this->conf['margin-*'] && isset($left)) {
            $style['margin-left'] = number_format($left, 2, '.', '') . 'pt';
        }
        return $style;
    }

    protected function processConfig() {
        $parts = pathinfo(__FILE__);
        $ini = $parts['dirname'] . '/' . $parts['filename'] . '.ini';
        if (file_exists($ini)) {
            if (($conf = parse_ini_file($ini, true)) !== false) {
                foreach ($conf as $key => $val) {
                    if (empty($val) && ($val != 0)) {
                        unset($conf[$key]);
                    }
                }
                $this->conf = array_merge($this->conf, $conf);
            }
        }
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

    protected function buildStyleSheet($styles, $sel = '') {
        $retval = '';
        if (!empty($styles)) {
            foreach ($styles as $name => $def) {
                $retval .= str_repeat(' ', 8) . $sel . $name . ' {' . PHP_EOL;
                foreach ($def as $prop => $val) {
                    $retval .= str_repeat(' ', 12) . $prop . ': ' . (is_array($val) ? implode(' ', $val) : $val) . ';' . PHP_EOL;
                }
                $retval .= str_repeat(' ', 8) . '}' . PHP_EOL;
            }
        }
        return $retval;
    }

    protected function buildHtmlOpen() {
        return '<!DOCTYPE html>' . PHP_EOL .
        '<html>' . PHP_EOL .
            '<head>' . PHP_EOL .
                str_repeat(' ', 4) . '<meta charset="UTF-8">' . PHP_EOL .
                str_repeat(' ', 4) . '<title>' . (empty($this->meta['title']) ?
                    htmlspecialchars($this->sla_parts['filename'] . '.html') :
                    htmlspecialchars($this->meta['title'])
                ) . '</title>' . PHP_EOL .
                str_repeat(' ', 4) . '<meta name="author" content="' . htmlspecialchars($this->meta['author']) . '">' . PHP_EOL .
                str_repeat(' ', 4) . '<meta name="description" content="' . (empty($this->meta['comment']) ?
                    '' :
                    htmlspecialchars($this->meta['comment']) . ' '
                ) . 'Formatted text extracted from ' . htmlspecialchars($this->sla_parts['basename']) . ' by Scribus2html.php">' . PHP_EOL .
                str_repeat(' ', 4) . '<meta name="keywords" content="scribus2html">' . PHP_EOL .
                str_repeat(' ', 4) . '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . PHP_EOL .
                ($this->conf['style-sheet'] ?
                    str_repeat(' ', 4) . '<style>' . PHP_EOL .
                        (isset($this->conf['html-tag-style']) ? $this->buildStyleSheet($this->conf['html-tag-style']) : '') .
                        $this->buildStyleSheet($this->stylesheet, '.') .
                    str_repeat(' ', 4) . '</style>' . PHP_EOL :
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
                    if ($this->conf['tab-as-comment']) {
                        // scan for tabular data
                        $i = 0;
                        $par_count = count($paragraphs);
                        while ($i < $par_count) {
                            if ($tab_count = substr_count($paragraphs[$i], $this->conf['html-tab-fake'])) {
                                $table = [];
                                while (($i < $par_count) && (substr_count($paragraphs[$i], $this->conf['html-tab-fake']) == $tab_count)) {
                                    $table[] = $paragraphs[$i];
                                    $i++;
                                }
                                if (count($table) > 1) {
                                    // yes, table encountered
                                    $html .= '<table>' . PHP_EOL;
                                    foreach ($table as $row) {
                                        // convert paragraph to table row
                                        $row = preg_replace('#<p([^>]*?)>#', '<tr$1><td>', $row);
                                        $row = str_replace($this->conf['html-tab-fake'], '</td><td>', $row);
                                        $row = str_replace('</p>', '</td></tr>', $row);
                                        $html .= $row . PHP_EOL;
                                    }
                                    $html .= '</table>' . PHP_EOL;
                                } else {
                                    // no, single paragraph with tabs
                                    $html .= $table[0] . PHP_EOL;
                                }
                            } else {
                                $html .= $paragraphs[$i] . PHP_EOL;
                                $i++;
                            }
                        }
                    } else {
                        // ignore tabular data
                        foreach ($paragraphs as $par) {
                            $html .= $par . PHP_EOL;
                        }
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
