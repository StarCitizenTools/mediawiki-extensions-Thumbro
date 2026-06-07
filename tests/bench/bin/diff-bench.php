<?php
declare( strict_types=1 );

/**
 * Compare two benchmark results.json files cell-by-cell (size + quality), for proving a
 * behaviour-preserving change produced byte-identical output. Prints "IDENTICAL" and exits 0
 * when every contender's bytes and SSIMULACRA2 mean match across both runs; otherwise lists the
 * differing cells and exits 1.
 *
 * Usage: php tests/bench/bin/diff-bench.php <baseline-results.json> <after-results.json>
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php diff-bench.php <baseline.json> <after.json>\n" );
	exit( 2 );
}

/**
 * Index rows by "source@width|role:name" => {bytes, quality-mean}.
 *
 * @return array<string,array{bytes:?int,q:?float}>
 */
$indexRows = static function ( array $rows ): array {
	$out = [];
	foreach ( $rows as $r ) {
		$key = basename( (string)( $r['source'] ?? '' ) ) . '@' . ( $r['width'] ?? '' );
		foreach ( $r['candidates'] ?? [] as $name => $c ) {
			$out["$key|cand:$name"] = [
				'bytes' => $c['result']['bytes'] ?? null,
				'q' => isset( $c['quality']['mean'] ) ? (float)$c['quality']['mean'] : null,
			];
		}
		foreach ( $r['baselines'] ?? [] as $name => $b ) {
			$out["$key|base:$name"] = [
				'bytes' => $b['bytes'] ?? null,
				'q' => isset( $r['baselineQualities'][$name]['mean'] )
					? (float)$r['baselineQualities'][$name]['mean'] : null,
			];
		}
	}
	return $out;
};

$a = $indexRows( json_decode( (string)file_get_contents( $argv[1] ), true ) ?: [] );
$b = $indexRows( json_decode( (string)file_get_contents( $argv[2] ), true ) ?: [] );

$diffs = [];
$keys = array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) );
sort( $keys );
foreach ( $keys as $k ) {
	$x = $a[$k] ?? null;
	$y = $b[$k] ?? null;
	if ( $x === null ) {
		$diffs[] = "$k: only in AFTER";
		continue;
	}
	if ( $y === null ) {
		$diffs[] = "$k: only in BASELINE";
		continue;
	}
	if ( $x['bytes'] !== $y['bytes'] ) {
		$diffs[] = "$k: bytes " . json_encode( $x['bytes'] ) . ' -> ' . json_encode( $y['bytes'] );
	}
	if ( $x['q'] !== null && $y['q'] !== null && abs( $x['q'] - $y['q'] ) > 0.001 ) {
		$diffs[] = "$k: quality {$x['q']} -> {$y['q']}";
	}
}

if ( $diffs === [] ) {
	echo "IDENTICAL\n";
	exit( 0 );
}
echo 'DIFFERENCES (' . count( $diffs ) . "):\n" . implode( "\n", $diffs ) . "\n";
exit( 1 );
