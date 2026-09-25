<?php
/**
 * A small table PDF writer: Helvetica, landscape, a title, a generated date, and a
 * paginated table whose header row repeats on every page. No external libraries.
 *
 *   $pdf = new WIC_Pdf( 'Training record', 'Letter' );
 *   $pdf->table( array( 'Name', 'Course' ), $rows, array( 2, 3 ) );  // relative widths
 *   $pdf->send( 'training-record.pdf' );                          // or ->render()
 */

defined( 'ABSPATH' ) || exit;

class WIC_Pdf {

	/** Helvetica advance widths (1/1000 em) for ASCII 32–126, from the standard AFM. */
	const WIDTHS = array( 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584 );

	private $title;
	private $subtitle;
	private $w;
	private $h;
	private $margin = 36;
	private $blocks = array();

	public function __construct( $title, $size = 'Letter', $subtitle = '' ) {
		$this->title    = (string) $title;
		$this->subtitle = $subtitle ? (string) $subtitle : sprintf( 'Generated %s', current_time( 'Y-m-d H:i' ) );
		// Landscape.
		if ( 'A4' === $size ) {
			$this->w = 842;
			$this->h = 595;
		} else {
			$this->w = 792;
			$this->h = 612;
		}
	}

	/** Add a paragraph of plain text above or between tables. */
	public function text( $text, $bold = false ) {
		$this->blocks[] = array( 'text', (string) $text, (bool) $bold );
		return $this;
	}

	/**
	 * @param string[]   $headers Column headings.
	 * @param array[]    $rows    Rows of cell values.
	 * @param int[]|null $widths  Relative column widths; equal when omitted.
	 */
	public function table( $headers, $rows, $widths = null ) {
		$this->blocks[] = array( 'table', array_values( (array) $headers ), array_map( 'array_values', (array) $rows ), $widths );
		return $this;
	}

	public function send( $filename ) {
		$body = $this->render();
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF.
		exit;
	}

	/* ------------------------------------------------------------------ */

	private static function enc( $s ) {
		$s = (string) $s;
		if ( function_exists( 'iconv' ) ) {
			$c = @iconv( 'UTF-8', 'windows-1252//TRANSLIT', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $c ) {
				return $c;
			}
		}
		return preg_replace( '/[^\x20-\x7E]/', '?', function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s );
	}

	private static function esc( $s ) {
		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', '', ' ' ), $s );
	}

	/** Width in points of an already-encoded string. */
	private static function width( $s, $size, $bold = false ) {
		$w   = 0;
		$len = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$o  = ord( $s[ $i ] );
			$w += ( $o >= 32 && $o <= 126 ) ? self::WIDTHS[ $o - 32 ] : 556;
		}
		return $w * $size / 1000 * ( $bold ? 1.06 : 1 );
	}

	/** Word-wrap encoded text to a width; long words are broken. */
	private static function wrap( $s, $max, $size, $bold = false ) {
		$lines = array();
		foreach ( explode( "\n", str_replace( "\r", '', $s ) ) as $para ) {
			$line = '';
			foreach ( preg_split( '/ +/', $para ) as $word ) {
				$try = '' === $line ? $word : $line . ' ' . $word;
				if ( self::width( $try, $size, $bold ) <= $max ) {
					$line = $try;
					continue;
				}
				if ( '' !== $line ) {
					$lines[] = $line;
				}
				while ( self::width( $word, $size, $bold ) > $max && strlen( $word ) > 1 ) {
					$cut = strlen( $word );
					while ( $cut > 1 && self::width( substr( $word, 0, $cut ), $size, $bold ) > $max ) {
						$cut--;
					}
					$lines[] = substr( $word, 0, $cut );
					$word    = substr( $word, $cut );
				}
				$line = $word;
			}
			$lines[] = $line;
		}
		return $lines;
	}

	public function render() {
		$pages   = array();
		$page    = '';
		$size    = 8.5;
		$lead    = 11;
		$pad     = 3;
		$usable  = $this->w - 2 * $this->margin;
		$bottom  = $this->margin + 18;
		$y       = 0;

		$new_page = function () use ( &$pages, &$page, &$y ) {
			if ( '' !== $page ) {
				$pages[] = $page;
			}
			$page = '';
			$y    = $this->h - $this->margin;
			$page .= $this->txt( $this->margin, $y - 14, $this->title, 14, true );
			$page .= $this->txt( $this->margin, $y - 28, $this->subtitle, 8.5, false, '0.35 0.38 0.43' );
			$y   -= 42;
		};
		$new_page();

		foreach ( $this->blocks as $b ) {
			if ( 'text' === $b[0] ) {
				foreach ( self::wrap( self::enc( $b[1] ), $usable, 9.5, $b[2] ) as $line ) {
					if ( $y - $lead < $bottom ) {
						$new_page();
					}
					$page .= $this->txt( $this->margin, $y - 10, $line, 9.5, $b[2], null, true );
					$y    -= $lead + 1;
				}
				$y -= 4;
				continue;
			}
			list( , $headers, $rows, $widths ) = $b;
			$n = max( 1, count( $headers ) );
			if ( ! $widths || count( $widths ) !== $n ) {
				$widths = array_fill( 0, $n, 1 );
			}
			$total = array_sum( $widths );
			$cols  = array();
			foreach ( $widths as $wv ) {
				$cols[] = $usable * $wv / $total;
			}
			$head_cells = array();
			foreach ( $headers as $i => $hv ) {
				$head_cells[] = self::wrap( self::enc( $hv ), $cols[ $i ] - 2 * $pad, $size, true );
			}
			$draw_row = function ( $cells, $bold, $shade ) use ( &$page, &$y, $cols, $size, $lead, $pad ) {
				$lines = 1;
				foreach ( $cells as $c ) {
					$lines = max( $lines, count( $c ) );
				}
				$hgt = $lines * $lead + 2 * $pad;
				if ( $shade ) {
					$page .= sprintf( "q %s rg %.2F %.2F %.2F %.2F re f Q\n", $shade, $this->margin, $y - $hgt, array_sum( $cols ), $hgt );
				}
				$x = $this->margin;
				foreach ( $cells as $i => $c ) {
					foreach ( $c as $li => $line ) {
						$page .= $this->txt( $x + $pad, $y - $pad - ( $li + 1 ) * $lead + 2.5, $line, $size, $bold, null, true );
					}
					$x += $cols[ $i ];
				}
				$page .= sprintf( "q 0.8 0.82 0.85 RG 0.5 w %.2F %.2F m %.2F %.2F l S Q\n", $this->margin, $y - $hgt, $this->margin + array_sum( $cols ), $y - $hgt );
				$y -= $hgt;
				return $hgt;
			};
			$row_height = function ( $cells ) use ( $lead, $pad ) {
				$lines = 1;
				foreach ( $cells as $c ) {
					$lines = max( $lines, count( $c ) );
				}
				return $lines * $lead + 2 * $pad;
			};
			$head_h = $row_height( $head_cells );
			if ( $y - $head_h - $lead * 2 < $bottom ) {
				$new_page();
			}
			$draw_row( $head_cells, true, '0.93 0.94 0.96' );
			if ( ! $rows ) {
				$page .= $this->txt( $this->margin + $pad, $y - $lead, self::enc( 'No rows.' ), $size, false, null, true );
				$y    -= $lead + 2 * $pad;
			}
			foreach ( $rows as $r ) {
				$cells = array();
				for ( $i = 0; $i < $n; $i++ ) {
					$cells[] = self::wrap( self::enc( isset( $r[ $i ] ) ? $r[ $i ] : '' ), $cols[ $i ] - 2 * $pad, $size );
				}
				if ( $y - $row_height( $cells ) < $bottom ) {
					$new_page();
					$draw_row( $head_cells, true, '0.93 0.94 0.96' );
				}
				$draw_row( $cells, false, null );
			}
			$y -= 12;
		}
		$pages[] = $page;

		// Assemble objects: 1 catalog, 2 pages, 3 Helvetica, 4 Helvetica-Bold, then page + content pairs.
		$objs  = array();
		$kids  = array();
		$count = count( $pages );
		foreach ( $pages as $i => $content ) {
			$content .= $this->txt( $this->margin, $this->margin - 6, 'Page ' . ( $i + 1 ) . ' of ' . $count, 8, false, '0.35 0.38 0.43' );
			$page_id  = 5 + $i * 2;
			$kids[]   = $page_id . ' 0 R';
			$objs[ $page_id ]     = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $this->w . ' ' . $this->h . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . ( $page_id + 1 ) . ' 0 R >>';
			$objs[ $page_id + 1 ] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
		}
		$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objs[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $count . ' >>';
		$objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		ksort( $objs );

		$out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objs as $id => $body ) {
			$offsets[ $id ] = strlen( $out );
			$out           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $out );
		$max  = max( array_keys( $objs ) );
		$out .= 'xref' . "\n0 " . ( $max + 1 ) . "\n0000000000 65535 f \n";
		for ( $i = 1; $i <= $max; $i++ ) {
			$out .= sprintf( "%010d 00000 n \n", isset( $offsets[ $i ] ) ? $offsets[ $i ] : 0 );
		}
		$info = '(' . self::esc( self::enc( $this->title ) ) . ')';
		$out .= 'trailer' . "\n<< /Size " . ( $max + 1 ) . ' /Root 1 0 R /Info << /Title ' . $info . " >> >>\nstartxref\n" . $xref . "\n%%EOF";
		return $out;
	}

	private function txt( $x, $y, $s, $size, $bold = false, $color = null, $encoded = false ) {
		$s = $encoded ? $s : self::enc( $s );
		return sprintf( "BT %s/F%d %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $color ? $color . ' rg ' : '0.11 0.14 0.19 rg ', $bold ? 2 : 1, $size, $x, $y, self::esc( $s ) );
	}
}
