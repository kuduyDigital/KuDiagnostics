<?php
/**
 * Builds build/kuduy-mcp.zip (top folder kuduy-mcp/) and build/kuduy-mcp.zip.sha256.
 *
 * Usage: php scripts/build-release.php [--expect-version=1.2.3]
 * Fails when the Version header, Plugin::VERSION and the expected version (the tag, in CI) differ.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions -- CLI build script, not WordPress code.

$root  = dirname( __DIR__ );
$build = $root . '/build';
$stage = $build . '/release/kuduy-mcp';
$zip   = $build . '/kuduy-mcp.zip';

/**
 * Stop with a message.
 *
 * @param string $message Message.
 */
function kuduy_fail( string $message ): void {
	fwrite( STDERR, "build-release: {$message}\n" );
	exit( 1 );
}

$header   = preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( $root . '/kuduy-mcp.php' ), $m ) ? $m[1] : '';
$constant = preg_match( "/const VERSION = '([^']+)'/", (string) file_get_contents( $root . '/src/Plugin.php' ), $m ) ? $m[1] : '';
$expected = '';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( str_starts_with( $arg, '--expect-version=' ) ) {
		$expected = substr( $arg, strlen( '--expect-version=' ) );
	}
}
if ( '' === $header || $header !== $constant ) {
	kuduy_fail( "Version header ({$header}) and Plugin::VERSION ({$constant}) differ." );
}
if ( '' !== $expected && $expected !== $header ) {
	kuduy_fail( "Tag says {$expected} but the plugin is {$header}." );
}

/**
 * Delete a directory tree.
 *
 * @param string $dir Directory.
 */
function kuduy_rmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $dir );
}

/**
 * Copy a file or directory tree.
 *
 * @param string $from Source.
 * @param string $to   Target.
 */
function kuduy_copy( string $from, string $to ): void {
	if ( is_file( $from ) ) {
		@mkdir( dirname( $to ), 0777, true );
		copy( $from, $to );
		return;
	}
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		kuduy_copy( $file->getPathname(), $to . substr( $file->getPathname(), strlen( $from ) ) );
	}
}

kuduy_rmdir( $build . '/release' );
@unlink( $zip );
@unlink( $zip . '.sha256' );
// Only what the plugin needs at runtime; dev tooling, tests and docs stay out.
foreach ( array( 'kuduy-mcp.php', 'uninstall.php', 'src', 'composer.json', 'composer.lock', 'LICENSE', 'readme.txt' ) as $item ) {
	if ( file_exists( $root . '/' . $item ) ) {
		kuduy_copy( $root . '/' . $item, $stage . '/' . $item );
	}
}

$composer = getenv( 'COMPOSER_BIN' );
$composer = false !== $composer && '' !== $composer ? $composer : 'composer';
passthru( $composer . ' install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --working-dir=' . escapeshellarg( $stage ), $status );
if ( 0 !== $status || ! is_file( $stage . '/vendor/autoload.php' ) ) {
	kuduy_fail( 'composer install --no-dev failed.' );
}
unlink( $stage . '/composer.lock' );

$archive = new ZipArchive();
if ( true !== $archive->open( $zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	kuduy_fail( "cannot write {$zip}" );
}
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $stage, FilesystemIterator::SKIP_DOTS ) );
$count = 0;
foreach ( $files as $file ) {
	$relative = 'kuduy-mcp/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $stage ) + 1 ) );
	$archive->addFile( $file->getPathname(), $relative );
	++$count;
}
$archive->close();

$hash = hash_file( 'sha256', $zip );
file_put_contents( $zip . '.sha256', $hash . "  kuduy-mcp.zip\n" );
printf( "Built %s: version %s, %d files, %d bytes, sha256 %s\n", $zip, $header, $count, filesize( $zip ), $hash );
