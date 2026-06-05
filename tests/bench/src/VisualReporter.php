<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Thumbro\Bench;

class VisualReporter {
	private const WORST_N = 5;
	private const CALIBRATION_N = 3;

	/** @param array<int,array<string,mixed>> $rows */
	public function write( array $rows, string $outDir ): void {
		$visDir = $outDir . '/visual';
		if ( !is_dir( $visDir ) ) {
			mkdir( $visDir, 0777, true );
		}
		$selected = $this->select( $rows );

		$html = "<!doctype html><meta charset=utf-8><title>Thumbro bench</title>"
			. "<style>body{font:14px sans-serif}td{vertical-align:top;padding:8px;border:1px solid #ccc}"
			. "img{max-width:240px;display:block}figcaption{font-size:12px;color:#555}</style>"
			. "<h1>Visual review — " . count( $selected ) . " cases</h1><table>";

		foreach ( $selected as $sel ) {
			$row = $sel['row'];
			$why = $sel['why'];
			$html .= "<tr><td colspan=4><b>" . htmlspecialchars( basename( (string)$row['source'] ) )
				. "</b> @{$row['width']}px — <i>" . htmlspecialchars( $why ) . "</i></td></tr><tr>";
			$cells = [];
			foreach ( $row['baselines'] as $name => $r ) {
				if ( $r->available ) {
					$cells[] = $this->cell( $r->thumbPath, "baseline $name — {$r->bytes} B", $visDir );
				}
			}
			foreach ( $row['candidates'] as $name => $c ) {
				$r = $c['result'];
				if ( $r->available ) {
					$q = $c['quality'];
					$cap = "$name — {$r->bytes} B" . ( $q !== null ? ' — SSIM2 ' . round( $q->mean, 1 ) : '' );
					$cells[] = $this->cell( $r->thumbPath, $cap, $visDir );
				}
			}
			$html .= '<td>' . implode( '</td><td>', $cells ) . '</td></tr>';
		}
		$html .= '</table>';
		file_put_contents( $visDir . '/index.html', $html );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array{row:array<string,mixed>,why:string}>
	 */
	private function select( array $rows ): array {
		$out = [];
		$scored = [];
		foreach ( $rows as $row ) {
			$incomparable = false;
			$minQ = INF;
			foreach ( $row['candidates'] as $c ) {
				if ( $c['quality'] !== null ) {
					$minQ = min( $minQ, $c['quality']->mean );
				}
				foreach ( $c['verdicts'] as $v ) {
					if ( $v->verdict === Verdict::INCOMPARABLE ) {
						$incomparable = true;
					}
				}
			}
			if ( $incomparable ) {
				$out[] = [ 'row' => $row, 'why' => 'INCOMPARABLE — needs trade-off decision' ];
			}
			if ( $minQ !== INF ) {
				$scored[] = [ 'row' => $row, 'q' => $minQ ];
			}
		}
		usort( $scored, static fn ( $a, $b ) => $a['q'] <=> $b['q'] );
		foreach ( array_slice( $scored, 0, self::WORST_N ) as $s ) {
			$out[] = [ 'row' => $s['row'], 'why' => 'worst-scoring (' . round( $s['q'], 1 ) . ')' ];
		}
		foreach ( array_slice( $scored, 0, self::CALIBRATION_N ) as $s ) {
			$out[] = [ 'row' => $s['row'], 'why' => 'calibration sample' ];
		}
		return $out;
	}

	private function cell( string $thumbPath, string $caption, string $visDir ): string {
		$name = basename( dirname( $thumbPath ) ) . '_' . basename( $thumbPath );
		if ( !copy( $thumbPath, $visDir . '/' . $name ) ) {
			throw new \RuntimeException( 'Failed to copy thumbnail into visual dir: ' . $thumbPath );
		}
		return '<figure><img src="' . htmlspecialchars( $name ) . '">'
			. '<figcaption>' . htmlspecialchars( $caption ) . '</figcaption></figure>';
	}
}
