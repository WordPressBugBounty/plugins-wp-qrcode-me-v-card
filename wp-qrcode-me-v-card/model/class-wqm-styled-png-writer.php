<?php
/**
 * PNG writer with stylized QR modules (dots, rounded, diamond, slanted, liquid, …).
 *
 * Drop-in replacement for the previous styled writer. Designed so the visual
 * styles are clearly distinguishable from each other and resemble the popular
 * QR code looks (rounded "pills", liquid blobs, dotted, diamonds, slanted
 * bricks, classic squares) including custom-shaped finder patterns.
 *
 * Rendering strategy:
 *   1. Pick a large integer "cell size" (px-per-module) so all shapes are crisp.
 *   2. Render shapes on that big canvas using neighbour-aware logic for styles
 *      that connect adjacent modules (rounded, liquid).
 *   3. Render the three finder patterns with a per-style custom geometry.
 *   4. Resample once down to the QR code's target outer size.
 */

defined( 'ABSPATH' ) || exit;

use Endroid\QrCode\Exception\GenerateImageException;
use Endroid\QrCode\Exception\MissingFunctionException;
use Endroid\QrCode\Exception\MissingLogoHeightException;
use Endroid\QrCode\Exception\ValidationException;
use Endroid\QrCode\QrCodeInterface;
use Endroid\QrCode\Writer\AbstractWriter;
use Zxing\QrReader;

class WQM_Styled_Png_Writer extends AbstractWriter {

	/** @var string */
	private $style_slug;

	/** Target px-per-module on the high-res render canvas. */
	const CELL_PX = 30;

	public function __construct( string $style_slug ) {
		$this->style_slug = $style_slug;
	}

	public static function slug_is_styled( string $slug ): bool {
		return '' !== $slug && 'square' !== $slug;
	}

	public function writeString( QrCodeInterface $qr_code ): string {
		if ( ! extension_loaded( 'gd' ) ) {
			throw new GenerateImageException( 'Unable to generate image: check your GD installation' );
		}

		$image = $this->create_image( $qr_code->getData(), $qr_code );

		$logo_path = $qr_code->getLogoPath();
		if ( null !== $logo_path ) {
			$image = $this->add_logo( $image, $logo_path, $qr_code->getLogoWidth(), $qr_code->getLogoHeight() );
		}

		$label = $qr_code->getLabel();
		if ( null !== $label ) {
			$image = $this->add_label( $image, $label, $qr_code->getLabelFontPath(), $qr_code->getLabelFontSize(), $qr_code->getLabelAlignment(), $qr_code->getLabelMargin(), $qr_code->getForegroundColor(), $qr_code->getBackgroundColor() );
		}

		$string = $this->image_to_string( $image );

		if ( PHP_VERSION_ID < 80000 ) {
			imagedestroy( $image );
		}

		if ( $qr_code->getValidateResult() ) {
			$reader = new QrReader( $string, QrReader::SOURCE_TYPE_BLOB );
			if ( $reader->text() !== $qr_code->getText() ) {
				throw new ValidationException( 'Built-in validation reader read incompatible text; disable validation or simplify style.' );
			}
		}

		return $string;
	}

	/**
	 * Build the styled QR image and resample it to the requested outer size.
	 *
	 * @param array<string,mixed> $data
	 * @return resource|\GdImage
	 */
	private function create_image( array $data, QrCodeInterface $qr_code ) {
		$matrix      = $data['matrix'];
		$block_count = (int) $data['block_count'];
		$cell        = self::CELL_PX;
		$inner_size  = $block_count * $cell;

		$big = imagecreatetruecolor( $inner_size, $inner_size );
		if ( ! $big ) {
			throw new GenerateImageException( 'Unable to allocate render canvas' );
		}
		imagealphablending( $big, true );
		imagesavealpha( $big, true );
		imageantialias( $big, true );

		$fg_rgb = $qr_code->getForegroundColor();
		$bg_rgb = $qr_code->getBackgroundColor();

		$bg = imagecolorallocatealpha(
			$big,
			(int) $bg_rgb['r'], (int) $bg_rgb['g'], (int) $bg_rgb['b'],
			isset( $bg_rgb['a'] ) ? (int) $bg_rgb['a'] : 0
		);
		$fg = imagecolorallocatealpha(
			$big,
			(int) $fg_rgb['r'], (int) $fg_rgb['g'], (int) $fg_rgb['b'],
			isset( $fg_rgb['a'] ) ? (int) $fg_rgb['a'] : 0
		);

		imagefilledrectangle( $big, 0, 0, $inner_size, $inner_size, $bg );

		// Build neighbour map AND finder mask.
		$is_on    = $this->matrix_to_bool( $matrix, $block_count );
		$is_finder = $this->mark_finder_modules( $block_count );

		// 1. Render data modules (skip finder cells; we draw them custom).
		for ( $r = 0; $r < $block_count; $r++ ) {
			for ( $c = 0; $c < $block_count; $c++ ) {
				if ( ! $is_on[ $r ][ $c ] || $is_finder[ $r ][ $c ] ) {
					continue;
				}
				$this->draw_module( $big, $r, $c, $cell, $fg, $bg, $is_on, $block_count );
			}
		}

		// 1b. Liquid post-processing: add convex fillets at "L-shaped" corners
		//     where 2 perpendicular ON neighbours meet but the diagonal is OFF.
		//     Producing the recognisable blob-merge silhouette.
		if ( 'liquid' === $this->style_slug ) {
			$this->liquid_post_pass( $big, $cell, $fg, $is_on, $is_finder, $block_count );
		}

		// 2. Render the three finder patterns with style-specific shapes.
		$this->draw_finder( $big, 0,                  0,                  $cell, $fg, $bg );
		$this->draw_finder( $big, 0,                  $block_count - 7,   $cell, $fg, $bg );
		$this->draw_finder( $big, $block_count - 7,   0,                  $cell, $fg, $bg );

		// 3. Compose final canvas (with margin) and downscale.
		$outer_w = (int) $data['outer_width'];
		$outer_h = (int) $data['outer_height'];

		$out = imagecreatetruecolor( $outer_w, $outer_h );
		if ( ! $out ) {
			throw new GenerateImageException( 'Unable to allocate output canvas' );
		}
		imagealphablending( $out, false );
		imagesavealpha( $out, true );
		$bg2 = imagecolorallocatealpha(
			$out,
			(int) $bg_rgb['r'], (int) $bg_rgb['g'], (int) $bg_rgb['b'],
			isset( $bg_rgb['a'] ) ? (int) $bg_rgb['a'] : 0
		);
		imagefilledrectangle( $out, 0, 0, $outer_w, $outer_h, $bg2 );

		imagecopyresampled(
			$out, $big,
			(int) $data['margin_left'], (int) $data['margin_left'],
			0, 0,
			(int) $data['inner_width'], (int) $data['inner_height'],
			$inner_size, $inner_size
		);

		if ( PHP_VERSION_ID < 80000 ) {
			imagedestroy( $big );
		}

		return $out;
	}

	/**
	 * @param array<int,array<int,int>> $matrix
	 * @return array<int,array<int,bool>>
	 */
	private function matrix_to_bool( array $matrix, int $n ): array {
		$out = [];
		for ( $r = 0; $r < $n; $r++ ) {
			$row = [];
			for ( $c = 0; $c < $n; $c++ ) {
				$row[ $c ] = isset( $matrix[ $r ][ $c ] ) && 1 === (int) $matrix[ $r ][ $c ];
			}
			$out[ $r ] = $row;
		}
		return $out;
	}

	/**
	 * Mark every module that belongs to one of the three 7×7 finder patterns
	 * (top-left, top-right, bottom-left).
	 *
	 * @return array<int,array<int,bool>>
	 */
	private function mark_finder_modules( int $n ): array {
		$mask = [];
		for ( $r = 0; $r < $n; $r++ ) {
			$mask[ $r ] = array_fill( 0, $n, false );
		}
		$origins = [
			[ 0, 0 ],
			[ 0, $n - 7 ],
			[ $n - 7, 0 ],
		];
		foreach ( $origins as $o ) {
			for ( $dr = 0; $dr < 7; $dr++ ) {
				for ( $dc = 0; $dc < 7; $dc++ ) {
					$rr = $o[0] + $dr;
					$cc = $o[1] + $dc;
					if ( $rr >= 0 && $rr < $n && $cc >= 0 && $cc < $n ) {
						$mask[ $rr ][ $cc ] = true;
					}
				}
			}
		}
		return $mask;
	}

	/**
	 * Drawing entry-point for one ON data module (not a finder cell).
	 *
	 * @param array<int,array<int,bool>> $is_on
	 */
	private function draw_module( $img, int $r, int $c, int $cell, int $fg, int $bg, array $is_on, int $n ): void {
		$x1 = $c * $cell;
		$y1 = $r * $cell;
		$x2 = $x1 + $cell - 1;
		$y2 = $y1 + $cell - 1;

		$top    = $r > 0      && $is_on[ $r - 1 ][ $c ];
		$bot    = $r < $n - 1 && $is_on[ $r + 1 ][ $c ];
		$left   = $c > 0      && $is_on[ $r ][ $c - 1 ];
		$right  = $c < $n - 1 && $is_on[ $r ][ $c + 1 ];

		switch ( $this->style_slug ) {

			case 'dots':
				// Crisp circles, no neighbour merging.
				$d = (int) round( $cell * 0.92 );
				$cx = (int) round( ( $x1 + $x2 ) / 2 );
				$cy = (int) round( ( $y1 + $y2 ) / 2 );
				imagefilledellipse( $img, $cx, $cy, $d, $d, $fg );
				return;

			case 'rounded':
				// Pills: square fill but with rounded *outer* corners only.
				$this->draw_rounded_module( $img, $x1, $y1, $x2, $y2, $cell, $fg, $bg, $top, $bot, $left, $right );
				return;

			case 'diamond':
				// Rotated squares with a small gap between cells.
				$pad = (int) round( $cell * 0.06 );
				$xa  = $x1 + $pad;
				$ya  = $y1 + $pad;
				$xb  = $x2 - $pad;
				$yb  = $y2 - $pad;
				$cx  = ( $xa + $xb ) / 2;
				$cy  = ( $ya + $yb ) / 2;
				$h   = ( $yb - $ya ) / 2;
				$w   = ( $xb - $xa ) / 2;
				$poly = [
					(int) round( $cx ),         (int) round( $cy - $h ),
					(int) round( $cx + $w ),    (int) round( $cy ),
					(int) round( $cx ),         (int) round( $cy + $h ),
					(int) round( $cx - $w ),    (int) round( $cy ),
				];
				imagefilledpolygon( $img, $poly, $fg );
				return;

			case 'slanted':
				// Tilted rectangles ("brickwall" feel). Same tilt for every cell so
				// it reads as a deliberate stylistic choice instead of noise.
				$pad = (int) round( $cell * 0.10 );
				$this->draw_rotated_rect(
					$img,
					$x1 + $pad, $y1 + $pad, $x2 - $pad, $y2 - $pad,
					-22.0, $fg
				);
				return;

			case 'liquid':
				// Connected blob style: full square + outer corner rounding +
				// concave fillets where two perpendicular neighbours meet.
				$this->draw_liquid_module( $img, $r, $c, $cell, $fg, $bg, $is_on, $n );
				return;

			case 'square':
			default:
				imagefilledrectangle( $img, $x1, $y1, $x2, $y2, $fg );
				return;
		}
	}

	/* ---------------------------------------------------------------------
	 *  ROUNDED ("pill") style — neighbour-aware rounded outer corners.
	 * --------------------------------------------------------------------- */
	private function draw_rounded_module( $img, int $x1, int $y1, int $x2, int $y2, int $cell, int $fg, int $bg, bool $top, bool $bot, bool $left, bool $right ): void {
		$r = (int) round( $cell * 0.45 );
		// Body.
		imagefilledrectangle( $img, $x1, $y1, $x2, $y2, $fg );

		// Each corner is rounded only when there is NO neighbour on either of the two adjacent sides.
		// 'corner' = which side of the cell the arc lives on; (cx,cy) = arc center inside the cell.
		$corners = [
			[ ! $top && ! $left,  $x1,         $y1,         $x1 + $r, $y1 + $r ], // TL
			[ ! $top && ! $right, $x2 - $r,    $y1,         $x2 - $r, $y1 + $r ], // TR
			[ ! $bot && ! $left,  $x1,         $y2 - $r,    $x1 + $r, $y2 - $r ], // BL
			[ ! $bot && ! $right, $x2 - $r,    $y2 - $r,    $x2 - $r, $y2 - $r ], // BR
		];
		foreach ( $corners as $info ) {
			[ $on, $rx1, $ry1, $arc_cx, $arc_cy ] = $info;
			if ( ! $on ) {
				continue;
			}
			// Erase the r×r corner square …
			imagefilledrectangle( $img, $rx1, $ry1, $rx1 + $r, $ry1 + $r, $bg );
			// … then put back a filled disk centered at the in-cell corner so a quarter-circle remains.
			imagefilledellipse( $img, $arc_cx, $arc_cy, $r * 2, $r * 2, $fg );
		}
	}

	/* ---------------------------------------------------------------------
	 *  LIQUID style — connected blobs with concave fillets at corners
	 * --------------------------------------------------------------------- */
	private function draw_liquid_module( $img, int $r, int $c, int $cell, int $fg, int $bg, array $is_on, int $n ): void {
		$x1 = $c * $cell;
		$y1 = $r * $cell;
		$x2 = $x1 + $cell - 1;
		$y2 = $y1 + $cell - 1;

		$top   = $r > 0      && $is_on[ $r - 1 ][ $c ];
		$bot   = $r < $n - 1 && $is_on[ $r + 1 ][ $c ];
		$left  = $c > 0      && $is_on[ $r ][ $c - 1 ];
		$right = $c < $n - 1 && $is_on[ $r ][ $c + 1 ];

		// Heavy outer rounding (the more rounding, the more "liquid" feel).
		// Same neighbour-aware corner trick as 'rounded' but with a much larger
		// radius so that an isolated cell becomes a full circle and adjacent
		// cells fuse into smooth elongated capsules / blobs.
		$radius = (int) round( $cell * 0.5 );

		// Body fill.
		imagefilledrectangle( $img, $x1, $y1, $x2, $y2, $fg );

		$corners = [
			[ ! $top && ! $left,  $x1,            $y1,            $x1 + $radius,   $y1 + $radius ], // TL
			[ ! $top && ! $right, $x2 - $radius,  $y1,            $x2 - $radius,   $y1 + $radius ], // TR
			[ ! $bot && ! $left,  $x1,            $y2 - $radius,  $x1 + $radius,   $y2 - $radius ], // BL
			[ ! $bot && ! $right, $x2 - $radius,  $y2 - $radius,  $x2 - $radius,   $y2 - $radius ], // BR
		];
		foreach ( $corners as $info ) {
			[ $on, $rx1, $ry1, $arc_cx, $arc_cy ] = $info;
			if ( ! $on ) {
				continue;
			}
			imagefilledrectangle( $img, $rx1, $ry1, $rx1 + $radius, $ry1 + $radius, $bg );
			imagefilledellipse( $img, $arc_cx, $arc_cy, $radius * 2, $radius * 2, $fg );
		}
	}

	/* ---------------------------------------------------------------------
	 *  Finder patterns (the three big 7×7 corner markers).
	 *  We replace them with style-matching geometry to make each option
	 *  immediately recognizable at a glance.
	 * --------------------------------------------------------------------- */
	private function draw_finder( $img, int $row, int $col, int $cell, int $fg, int $bg ): void {
		$x = $col * $cell;
		$y = $row * $cell;
		$size = 7 * $cell;

		switch ( $this->style_slug ) {

			case 'dots': {
				// Outer ring (donut) + filled inner circle.
				$cx = $x + $size / 2;
				$cy = $y + $size / 2;
				imagefilledellipse( $img, (int) $cx, (int) $cy, $size,            $size,            $fg );
				imagefilledellipse( $img, (int) $cx, (int) $cy, (int) ( $size - 2 * $cell ), (int) ( $size - 2 * $cell ), $bg );
				imagefilledellipse( $img, (int) $cx, (int) $cy, 3 * $cell, 3 * $cell, $fg );
				return;
			}

			case 'rounded': {
				// Soft rounded squared "pill" frame + rounded inner pip.
				$r_outer = (int) round( $cell * 1.6 );
				$this->filled_roundrect( $img, $x, $y, $x + $size - 1, $y + $size - 1, $r_outer, $fg );
				$this->filled_roundrect( $img, $x + $cell, $y + $cell, $x + $size - 1 - $cell, $y + $size - 1 - $cell, (int) ( $r_outer * 0.85 ), $bg );
				$r_in = (int) round( $cell * 0.9 );
				$this->filled_roundrect( $img, $x + 2 * $cell, $y + 2 * $cell, $x + $size - 1 - 2 * $cell, $y + $size - 1 - 2 * $cell, $r_in, $fg );
				return;
			}

			case 'diamond': {
				// Square frame (kept readable) + diamond pip.
				imagefilledrectangle( $img, $x, $y, $x + $size - 1, $y + $size - 1, $fg );
				imagefilledrectangle( $img, $x + $cell, $y + $cell, $x + $size - 1 - $cell, $y + $size - 1 - $cell, $bg );
				$cx = $x + $size / 2;
				$cy = $y + $size / 2;
				$h = 1.5 * $cell;
				$pip = [
					(int) $cx,          (int) ( $cy - $h ),
					(int) ( $cx + $h ), (int) $cy,
					(int) $cx,          (int) ( $cy + $h ),
					(int) ( $cx - $h ), (int) $cy,
				];
				imagefilledpolygon( $img, $pip, $fg );
				return;
			}

			case 'slanted': {
				// Square frame with slight rounding + a slanted square pip.
				$r_outer = (int) round( $cell * 0.6 );
				$this->filled_roundrect( $img, $x, $y, $x + $size - 1, $y + $size - 1, $r_outer, $fg );
				$this->filled_roundrect( $img, $x + $cell, $y + $cell, $x + $size - 1 - $cell, $y + $size - 1 - $cell, (int) ( $r_outer * 0.9 ), $bg );
				$this->draw_rotated_rect(
					$img,
					$x + 2 * $cell, $y + 2 * $cell,
					$x + $size - 1 - 2 * $cell, $y + $size - 1 - 2 * $cell,
					-22.0, $fg
				);
				return;
			}

			case 'liquid': {
				// Heavily rounded squircle frame + circular pip — matches the blob theme.
				$r_outer = (int) round( $cell * 2.2 );
				$this->filled_roundrect( $img, $x, $y, $x + $size - 1, $y + $size - 1, $r_outer, $fg );
				$this->filled_roundrect( $img, $x + $cell, $y + $cell, $x + $size - 1 - $cell, $y + $size - 1 - $cell, (int) ( $r_outer * 0.8 ), $bg );
				$cx = $x + $size / 2;
				$cy = $y + $size / 2;
				imagefilledellipse( $img, (int) $cx, (int) $cy, 3 * $cell, 3 * $cell, $fg );
				return;
			}

			case 'square':
			default: {
				// Classic three-rect finder.
				imagefilledrectangle( $img, $x, $y, $x + $size - 1, $y + $size - 1, $fg );
				imagefilledrectangle( $img, $x + $cell, $y + $cell, $x + $size - 1 - $cell, $y + $size - 1 - $cell, $bg );
				imagefilledrectangle( $img, $x + 2 * $cell, $y + 2 * $cell, $x + $size - 1 - 2 * $cell, $y + $size - 1 - 2 * $cell, $fg );
				return;
			}
		}
	}

	/* ---------------------------------------------------------------------
	 *  Geometry helpers
	 * --------------------------------------------------------------------- */
	private function filled_roundrect( $img, int $x1, int $y1, int $x2, int $y2, int $rad, int $color ): void {
		$r = max( 0, min( $rad, (int) floor( min( $x2 - $x1, $y2 - $y1 ) / 2 ) ) );
		if ( $x2 <= $x1 || $y2 <= $y1 ) {
			return;
		}
		imagefilledrectangle( $img, $x1 + $r, $y1, $x2 - $r, $y2, $color );
		imagefilledrectangle( $img, $x1, $y1 + $r, $x2, $y2 - $r, $color );
		imagefilledellipse( $img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color );
		imagefilledellipse( $img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color );
		imagefilledellipse( $img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color );
		imagefilledellipse( $img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color );
	}

	private function draw_rotated_rect( $img, float $x1, float $y1, float $x2, float $y2, float $deg, int $color ): void {
		$cx = ( $x1 + $x2 ) / 2;
		$cy = ( $y1 + $y2 ) / 2;
		$r  = deg2rad( $deg );
		$w  = ( $x2 - $x1 ) / 2;
		$h  = ( $y2 - $y1 ) / 2;
		$cos = cos( $r );
		$sin = sin( $r );
		$pts = [ [ -$w, -$h ], [ $w, -$h ], [ $w, $h ], [ -$w, $h ] ];
		$poly = [];
		foreach ( $pts as $p ) {
			$xr = $p[0] * $cos - $p[1] * $sin;
			$yr = $p[0] * $sin + $p[1] * $cos;
			$poly[] = (int) round( $cx + $xr );
			$poly[] = (int) round( $cy + $yr );
		}
		imagefilledpolygon( $img, $poly, $color );
	}

	/**
	 * Build a square polygon whose 4 sides bow inward (concave) -> "pinched"
	 * star-ish shape used for the diamond-style finder.
	 *
	 * @return array<int>
	 */
	private function concave_square_polygon( float $cx, float $cy, float $r ): array {
		$bow = $r * 0.16;
		$pts = [];
		$corners = [
			[ -$r, -$r ],
			[ +$r, -$r ],
			[ +$r, +$r ],
			[ -$r, +$r ],
		];
		$side_mid = [
			[ 0,    -$r + $bow ], // top side bowed inward
			[ +$r - $bow, 0    ], // right
			[ 0,    +$r - $bow ], // bottom
			[ -$r + $bow, 0    ], // left
		];
		for ( $i = 0; $i < 4; $i++ ) {
			$pts[] = (int) round( $cx + $corners[ $i ][0] );
			$pts[] = (int) round( $cy + $corners[ $i ][1] );
			$pts[] = (int) round( $cx + $side_mid[ $i ][0] );
			$pts[] = (int) round( $cy + $side_mid[ $i ][1] );
		}
		return $pts;
	}

	private function erase_rect( $img, int $x1, int $y1, int $x2, int $y2, int $bg ): void {
		imagefilledrectangle( $img, $x1, $y1, $x2, $y2, $bg );
	}

	/**
	 * Liquid pass-2: at every OFF data cell whose two perpendicular ON neighbours
	 * touch (e.g. top + left both ON, but the top-left diagonal is OFF) we paint a
	 * convex fillet (a foreground disk) sitting in the OFF cell. The two ON cells
	 * already have rounded outer corners pointing into this OFF cell, so the disk
	 * fuses them into a smooth concave-free blob silhouette.
	 */
	private function liquid_post_pass( $img, int $cell, int $fg, array $is_on, array $is_finder, int $n ): void {
		$radius = (int) round( $cell * 0.5 );
		for ( $r = 0; $r < $n; $r++ ) {
			for ( $c = 0; $c < $n; $c++ ) {
				if ( $is_on[ $r ][ $c ] || $is_finder[ $r ][ $c ] ) {
					continue;
				}
				$x1 = $c * $cell;
				$y1 = $r * $cell;
				$x2 = $x1 + $cell - 1;
				$y2 = $y1 + $cell - 1;

				$top   = $r > 0      && $is_on[ $r - 1 ][ $c ] && ! $is_finder[ $r - 1 ][ $c ];
				$bot   = $r < $n - 1 && $is_on[ $r + 1 ][ $c ] && ! $is_finder[ $r + 1 ][ $c ];
				$left  = $c > 0      && $is_on[ $r ][ $c - 1 ] && ! $is_finder[ $r ][ $c - 1 ];
				$right = $c < $n - 1 && $is_on[ $r ][ $c + 1 ] && ! $is_finder[ $r ][ $c + 1 ];

				// Each L-corner places a foreground disk at the cell corner that sits
				// between the two ON neighbours.  Use a small radius so the disks read
				// as gentle fillets rather than re-creating squares.
				$pairs = [
					[ $top && $left,  $x1, $y1 ],
					[ $top && $right, $x2, $y1 ],
					[ $bot && $left,  $x1, $y2 ],
					[ $bot && $right, $x2, $y2 ],
				];
				foreach ( $pairs as $p ) {
					if ( ! $p[0] ) {
						continue;
					}
					imagefilledellipse( $img, (int) $p[1], (int) $p[2], $radius * 2, $radius * 2, $fg );
				}
			}
		}
	}

	/* ---------------------------------------------------------------------
	 *  Logo + label + output (same as previous writer)
	 * --------------------------------------------------------------------- */
	private function add_logo( $source_image, string $logo_path, int $logo_width = null, int $logo_height = null ) {
		$mime_type = $this->getMimeType( $logo_path );
		$contents  = file_get_contents( $logo_path );
		if ( false === $contents ) {
			throw new GenerateImageException( 'Logo file could not be read' );
		}
		$logo_image = imagecreatefromstring( strval( $contents ) );
		if ( 'image/svg+xml' === $mime_type && ( null === $logo_height || null === $logo_width ) ) {
			throw new MissingLogoHeightException( 'SVG logos require explicit width & height.' );
		}
		if ( ! $logo_image ) {
			throw new GenerateImageException( 'Unable to load logo image' );
		}
		$src_w = imagesx( $logo_image );
		$src_h = imagesy( $logo_image );
		if ( null === $logo_width ) {
			$logo_width = $src_w;
		}
		if ( null === $logo_height ) {
			$aspect      = $logo_width / max( $src_w, 1 );
			$logo_height = (int) floor( $src_h * $aspect );
		}
		$x = (int) floor( imagesx( $source_image ) / 2 - $logo_width / 2 );
		$y = (int) floor( imagesy( $source_image ) / 2 - $logo_height / 2 );
		imagecopyresampled( $source_image, $logo_image, $x, $y, 0, 0, (int) $logo_width, (int) $logo_height, $src_w, $src_h );
		if ( PHP_VERSION_ID < 80000 ) {
			imagedestroy( $logo_image );
		}
		return $source_image;
	}

	private function add_label( $source_image, string $label, string $label_font_path, int $label_font_size, string $label_alignment, array $label_margin, array $foreground_color, array $background_color ) {
		if ( ! function_exists( 'imagettfbbox' ) ) {
			throw new MissingFunctionException( 'Missing function imagettfbbox — install FreeType for GD.' );
		}
		$label_box = imagettfbbox( $label_font_size, 0, $label_font_path, $label );
		if ( ! $label_box ) {
			throw new GenerateImageException( 'Unable to add label — font issue' );
		}
		$lw = (int) ( $label_box[2] - $label_box[0] );
		$lh = (int) ( $label_box[0] - $label_box[7] );
		$sw = imagesx( $source_image );
		$sh = imagesy( $source_image );
		$tw = $sw;
		$th = $sh + $lh + $label_margin['t'] + $label_margin['b'];
		$target = imagecreatetruecolor( $tw, $th );
		$fg = imagecolorallocate( $target, (int) $foreground_color['r'], (int) $foreground_color['g'], (int) $foreground_color['b'] );
		$bg = imagecolorallocate( $target, (int) $background_color['r'], (int) $background_color['g'], (int) $background_color['b'] );
		imagefill( $target, 0, 0, $bg );
		imagecopyresampled( $target, $source_image, 0, 0, 0, 0, $sw, $sh, $sw, $sh );
		if ( PHP_VERSION_ID < 80000 ) {
			imagedestroy( $source_image );
		}
		switch ( strtolower( $label_alignment ) ) {
			case 'left':  $lx = (int) $label_margin['l']; break;
			case 'right': $lx = $tw - $lw - (int) $label_margin['r']; break;
			default:      $lx = (int) ( $tw / 2 - $lw / 2 );
		}
		$ly = $th - (int) $label_margin['b'];
		imagettftext( $target, $label_font_size, 0, $lx, $ly, $fg, $label_font_path, $label );
		return $target;
	}

	private function image_to_string( $image ): string {
		ob_start();
		imagepng( $image );
		return (string) ob_get_clean();
	}

	public static function getContentType(): string { return 'image/png'; }
	public static function getSupportedExtensions(): array { return [ 'png' ]; }
	public function getName(): string { return 'png-styled-' . preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $this->style_slug ) ); }
}
