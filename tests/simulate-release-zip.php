<?php
/**
 * Local simulation of .github/workflows/release-zip.yml packaging steps.
 *
 * Usage: php tests/simulate-release-zip.php
 *
 * @package DeoiaSubscriptions
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$plugin_slug = 'deoia-subscriptions';
$stage = sys_get_temp_dir() . '/deoia-subs-zip-sim-' . getmypid();
$plugin_dir = $stage . '/' . $plugin_slug;
$zip_path = $stage . '/' . $plugin_slug . '.zip';

function deoia_sim_rm_rf( string $path ): void {
	if ( ! file_exists( $path ) ) {
		return;
	}
	if ( is_file( $path ) || is_link( $path ) ) {
		unlink( $path );
		return;
	}
	$items = scandir( $path );
	if ( ! is_array( $items ) ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( $item === '.' || $item === '..' ) {
			continue;
		}
		deoia_sim_rm_rf( $path . '/' . $item );
	}
	rmdir( $path );
}

function deoia_sim_copy_tree( string $src, string $dst, array $exclude_names, array $exclude_root_names ): void {
	if ( ! is_dir( $dst ) ) {
		mkdir( $dst, 0777, true );
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	$src_len = strlen( rtrim( $src, '/\\' ) ) + 1;

	foreach ( $iterator as $item ) {
		/** @var SplFileInfo $item */
		$relative = substr( $item->getPathname(), $src_len );
		$relative = str_replace( '\\', '/', $relative );
		$parts = explode( '/', $relative );

		if ( $parts[0] !== '' && in_array( $parts[0], $exclude_root_names, true ) ) {
			continue;
		}

		$skip = false;
		foreach ( $parts as $part ) {
			if ( in_array( $part, $exclude_names, true ) ) {
				$skip = true;
				break;
			}
			if ( $part === '.env' || str_starts_with( $part, '.env.' ) || str_ends_with( $part, '.zip' ) || str_ends_with( $part, '.log' ) ) {
				$skip = true;
				break;
			}
		}
		if ( $skip ) {
			continue;
		}

		$target = $dst . '/' . $relative;
		if ( $item->isDir() ) {
			if ( ! is_dir( $target ) ) {
				mkdir( $target, 0777, true );
			}
			continue;
		}

		$target_dir = dirname( $target );
		if ( ! is_dir( $target_dir ) ) {
			mkdir( $target_dir, 0777, true );
		}
		if ( ! copy( $item->getPathname(), $target ) ) {
			fwrite( STDERR, "FAIL copy {$relative}\n" );
			exit( 1 );
		}
	}
}

deoia_sim_rm_rf( $stage );
mkdir( $plugin_dir, 0777, true );

$exclude_anywhere = array( '.git', '.github', '.vscode', '.idea', '.cursor', '.DS_Store', '.phpunit.result.cache' );
$exclude_root = array( 'tests', 'node_modules', 'vendor', 'dist', 'build', 'coverage' );

deoia_sim_copy_tree( $root, $plugin_dir, $exclude_anywhere, $exclude_root );

$checks = array(
	'plugin php' => is_file( $plugin_dir . '/' . $plugin_slug . '.php' ),
	'assets' => is_dir( $plugin_dir . '/assets' ),
	'includes' => is_dir( $plugin_dir . '/includes' ),
	'puc' => is_dir( $plugin_dir . '/plugin-update-checker' ),
	'puc license' => is_file( $plugin_dir . '/plugin-update-checker/license.txt' ),
	'no tests' => ! is_dir( $plugin_dir . '/tests' ),
	'no .git' => ! is_dir( $plugin_dir . '/.git' ),
	'no .github' => ! is_dir( $plugin_dir . '/.github' ),
	'no .env' => ! file_exists( $plugin_dir . '/.env' ),
);

foreach ( $checks as $label => $ok ) {
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL  staging check: {$label}\n" );
		exit( 1 );
	}
	echo "PASS  staging check: {$label}\n";
}

$main = (string) file_get_contents( $root . '/deoia-subscriptions.php' );
if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $hm ) ) {
	fwrite( STDERR, "FAIL  cannot parse Version header\n" );
	exit( 1 );
}
if ( ! preg_match( "/define\(\s*'DEOIA_SUBSCRIPTIONS_VERSION'\s*,\s*'([^']+)'\s*\)/", $main, $cm ) ) {
	fwrite( STDERR, "FAIL  cannot parse DEOIA_SUBSCRIPTIONS_VERSION\n" );
	exit( 1 );
}
if ( $hm[1] !== $cm[1] || $hm[1] !== '1.6.5' ) {
	fwrite( STDERR, "FAIL  version mismatch header={$hm[1]} const={$cm[1]}\n" );
	exit( 1 );
}
echo "PASS  version alignment header/const=1.6.5\n";

$zip = new ZipArchive();
if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
	fwrite( STDERR, "FAIL  cannot create zip\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS )
);
$base_len = strlen( $stage ) + 1;
foreach ( $iterator as $file ) {
	/** @var SplFileInfo $file */
	if ( ! $file->isFile() ) {
		continue;
	}
	$local = substr( $file->getPathname(), $base_len );
	$local = str_replace( '\\', '/', $local );
	$zip->addFile( $file->getPathname(), $local );
}
$zip->close();

$zip = new ZipArchive();
if ( $zip->open( $zip_path ) !== true ) {
	fwrite( STDERR, "FAIL  cannot open zip\n" );
	exit( 1 );
}

$names = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$names[] = $zip->getNameIndex( $i );
}
$zip->close();

$required_prefixes = array(
	$plugin_slug . '/' . $plugin_slug . '.php',
	$plugin_slug . '/assets/',
	$plugin_slug . '/includes/',
	$plugin_slug . '/plugin-update-checker/',
);

foreach ( $required_prefixes as $prefix ) {
	$found = false;
	foreach ( $names as $name ) {
		if ( $name === $prefix || str_starts_with( $name, $prefix ) ) {
			$found = true;
			break;
		}
	}
	if ( ! $found ) {
		fwrite( STDERR, "FAIL  missing in zip: {$prefix}\n" );
		exit( 1 );
	}
	echo "PASS  zip contains {$prefix}\n";
}

foreach ( $names as $name ) {
	if (
		str_contains( $name, '/tests/' )
		|| str_starts_with( $name, $plugin_slug . '/tests/' )
		|| str_contains( $name, '/.git/' )
		|| str_contains( $name, '/.github/' )
		|| str_contains( $name, '/.env' )
		|| str_ends_with( $name, '.log' )
	) {
		fwrite( STDERR, "FAIL  forbidden entry in zip: {$name}\n" );
		exit( 1 );
	}
}
echo "PASS  zip has no tests/.git/.github/.env/logs\n";

$roots = array();
foreach ( $names as $name ) {
	$parts = explode( '/', $name );
	if ( isset( $parts[0] ) && $parts[0] !== '' ) {
		$roots[ $parts[0] ] = true;
	}
}
if ( count( $roots ) !== 1 || ! isset( $roots[ $plugin_slug ] ) ) {
	fwrite( STDERR, 'FAIL  expected single root folder, got: ' . implode( ',', array_keys( $roots ) ) . "\n" );
	exit( 1 );
}
echo "PASS  single root folder {$plugin_slug}/\n";

$sample = array_slice( $names, 0, 25 );
echo "=== sample zip entries ===\n";
foreach ( $sample as $name ) {
	echo $name . "\n";
}

$out = '/tmp/deoia-subscriptions-sim.zip';
copy( $zip_path, $out );
echo "Saved {$out}\n";
echo "ZIP_SIMULATION_OK\n";

deoia_sim_rm_rf( $stage );
exit( 0 );
