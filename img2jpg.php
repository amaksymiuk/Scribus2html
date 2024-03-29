<?php

/**
 * Simple wrapper for ImageMagick.
 *
 * Scribus publication links many types of images: tiff, eps, ps, pdf, etc.
 * In order to use images in final web document, we need to do two things:
 * - convert images to format understandable by web browser,
 * - normalize images to reasonable sizes.
 * For the above purposes I use ImageMagick graphical application.
 */

namespace Scribus2html;

class img2jpg {

    const PATH_SEP = '/';
    const EXT_IN = '#^bmp|eps|gif|jpe?g|pdf|png|ps|svg|tiff?|webp|wmf$#i';
    const CMD = 'convert -define profile:skip="!icc,*" "%SRC%" -background white -flatten -alpha off -resize "%SIZE%x%SIZE%>" -density 72 -units pixelsperinch -quality 90 "%DST%"';
    const CMD_PDF = 'convert -density 144 "%SRC%" -background white -alpha off -resize "%SIZE%x%SIZE%>" -quality 90 "%DST%"';
    const CMD_CHECK = 'convert -version';

    protected $cmd;

    protected $output_size;

    public function __construct($img_in, $img_out, $size_out = 1024, $force_jpg = true) {
        if (file_exists($img_in)) {
            $parts_in = pathinfo($img_in);
            if (isset($parts_in['extension']) && preg_match(self::EXT_IN, $parts_in['extension'])) {
                $this->cmd = (self::isPdf($img_in) ?
                    self::CMD_PDF :
                    self::CMD
                );
                $this->cmd = str_replace('%SRC%', $img_in, $this->cmd);
                $this->cmd = str_replace('%SIZE%', $size_out, $this->cmd);
                if ($force_jpg) {
                    $parts_out = pathinfo($img_out);
                    $img_out = $parts_out['dirname'] . self::PATH_SEP . $parts_out['filename'] . '.jpg';
                }
                $this->cmd = str_replace('%DST%', $img_out, $this->cmd);
            }
        }
    }

    public function run() {
        $output = [];
        $exit_code = 0;
        exec($this->cmd, $output, $exit_code);
        return ($exit_code == 0);
    }

    public static function isPdf($file) {
        $retval = false;
        $parts = pathinfo($file);
        if (isset($parts['extension']) && preg_match('#^pdf$#i', $parts['extension'])) {
            $retval = true;
        }
        return $retval;
    }

    public static function isAvail() {
        $retval = false;
        $output = [];
        $exit_code = 0;
        exec(self::CMD_CHECK, $output, $exit_code);
        if (!empty($output)) {
            foreach ($output as $line) {
                if (preg_match('#ImageMagick#i', $line)) {
                    $retval = true;
                    break;
                }
            }
        }
        return ($exit_code == 0) && $retval;
    }

}
