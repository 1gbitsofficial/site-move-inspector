<?php
/**
 * Bounded filesystem metadata scanner.
 *
 * @package OneGbits_Site_Move_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans files in small, resumable batches without reading file contents.
 */
final class OGSMI_File_Scanner {

	const MAX_ENTRIES             = 100000;
	const MAX_TOTAL_SECONDS       = 60.0;
	const MAX_BATCH_ENTRIES       = 250;
	const MAX_BATCH_SECONDS       = 0.75;
	const MAX_DIRECTORY_ENTRIES   = 10000;
	const MAX_VISITED_DIRECTORIES = 25000;
	const MAX_SAMPLE_PATHS        = 20;
	const TOP_FILE_LIMIT          = 20;

	/**
	 * Create a new scan cursor.
	 *
	 * @return array
	 * @throws RuntimeException When the WordPress root cannot be resolved safely.
	 */
	public function create_state() {
		$locations = OGSMI_Locations::filesystem_snapshot();
		$root      = $locations['root'];
		if ( '' === $root || ! is_dir( $root ) ) {
			throw new RuntimeException( 'The WordPress scan root could not be resolved safely.' );
		}

		$marker_paths = array(
			'core'    => array( $locations['core'] ),
			'plugins' => array( $locations['plugins'] ),
			'themes'  => $locations['theme_roots'],
			'uploads' => array( $locations['uploads'] ),
		);

		$markers         = array();
		$partial_reasons = array();
		$excluded_count  = 0;

		foreach ( $marker_paths as $marker_id => $category_paths ) {
			$markers[ $marker_id ] = array();

			foreach ( array_unique( $category_paths ) as $marker_path ) {
				$marker = $this->resolve_marker( $marker_path, $root );
				if ( ! in_array( $marker['relative'], $markers[ $marker_id ], true ) ) {
					$markers[ $marker_id ][] = $marker['relative'];
				}

				if ( ! $marker['excluded'] ) {
					continue;
				}

				++$excluded_count;
				$partial_reasons[] = sprintf(
					/* translators: %s: type of WordPress directory, such as plugins or themes. */
					__( 'The %s directory is outside the WordPress root or could not be resolved, so it was not scanned.', '1gbits-site-move-inspector' ),
					$this->marker_label( $marker_id )
				);
			}
		}

		return array(
			'root'                    => $root,
			'markers'                 => $markers,
			'queue'                   => array( $root ),
			'current'                 => null,
			'visited'                 => array( md5( $this->comparison_path( $root ) ) => true ),
			'processed_entries'       => 0,
			'file_count'              => 0,
			'directory_count'         => 1,
			'total_bytes'             => 0,
			'elapsed_seconds'         => 0.0,
			'completed'               => false,
			'partial'                 => $excluded_count > 0,
			'partial_reasons'         => $partial_reasons,
			'categories'              => $this->empty_categories(),
			'top_files'               => array(),
			'symlink_count'           => 0,
			'symlink_samples'         => array(),
			'unreadable_count'        => 0,
			'unreadable_samples'      => array(),
			'outside_root_count'      => $excluded_count,
			'outside_root_samples'    => array(),
			'special_file_count'      => 0,
			'skipped_directory_count' => 0,
			'limits'                  => array(
				'entries'       => self::MAX_ENTRIES,
				'total_seconds' => self::MAX_TOTAL_SECONDS,
			),
		);
	}

	/**
	 * Process the next bounded batch.
	 *
	 * @param array $state Existing cursor.
	 * @return array Updated cursor.
	 */
	public function step( array $state ) {
		if ( ! empty( $state['completed'] ) ) {
			return $state;
		}

		$batch_started = microtime( true );
		$batch_entries = 0;

		while ( true ) {
			if ( $this->limit_reached( $state, $batch_started, $batch_entries ) ) {
				break;
			}

			if ( empty( $state['current'] ) ) {
				if ( empty( $state['queue'] ) ) {
					$state['completed'] = true;
					break;
				}

				$state['current'] = array(
					'path'           => array_pop( $state['queue'] ),
					'offset'         => 0,
					'last_entry_key' => '',
					'mtime'          => 0,
				);
			}

			$state = $this->scan_current_directory( $state, $batch_started, $batch_entries );
			if ( ! empty( $state['completed'] ) ) {
				break;
			}
		}

		$batch_elapsed             = max( 0.0, microtime( true ) - $batch_started );
		$state['elapsed_seconds'] += $batch_elapsed;

		if ( $state['elapsed_seconds'] >= self::MAX_TOTAL_SECONDS && ! $state['completed'] ) {
			$this->mark_partial(
				$state,
				__( 'The filesystem scan reached its cumulative time limit.', '1gbits-site-move-inspector' )
			);
			$this->finish_early( $state );
		}

		if ( $state['processed_entries'] >= self::MAX_ENTRIES && ! $state['completed'] ) {
			$this->mark_partial(
				$state,
				__( 'The filesystem scan reached its entry limit.', '1gbits-site-move-inspector' )
			);
			$this->finish_early( $state );
		}

		return $state;
	}

	/**
	 * Return a stable report-safe summary of the state.
	 *
	 * @param array $state Scanner state.
	 * @return array
	 */
	public function summarize( array $state ) {
		return array(
			'complete'                => ! empty( $state['completed'] ) && empty( $state['partial'] ),
			'partial'                 => ! empty( $state['partial'] ),
			'partial_reasons'         => array_values( $state['partial_reasons'] ?? array() ),
			'processed_entries'       => absint( $state['processed_entries'] ?? 0 ),
			'file_count'              => absint( $state['file_count'] ?? 0 ),
			'directory_count'         => absint( $state['directory_count'] ?? 0 ),
			'total_bytes'             => max( 0, (int) ( $state['total_bytes'] ?? 0 ) ),
			'elapsed_seconds'         => round( (float) ( $state['elapsed_seconds'] ?? 0 ), 2 ),
			'categories'              => array_values( $state['categories'] ?? array() ),
			'top_files'               => array_values( $state['top_files'] ?? array() ),
			'symlink_count'           => absint( $state['symlink_count'] ?? 0 ),
			'symlink_samples'         => array_values( $state['symlink_samples'] ?? array() ),
			'unreadable_count'        => absint( $state['unreadable_count'] ?? 0 ),
			'unreadable_samples'      => array_values( $state['unreadable_samples'] ?? array() ),
			'outside_root_count'      => absint( $state['outside_root_count'] ?? 0 ),
			'outside_root_samples'    => array_values( $state['outside_root_samples'] ?? array() ),
			'special_file_count'      => absint( $state['special_file_count'] ?? 0 ),
			'skipped_directory_count' => absint( $state['skipped_directory_count'] ?? 0 ),
			'limits'                  => $state['limits'] ?? array(),
		);
	}

	/**
	 * Scan a portion of the currently selected directory.
	 *
	 * @param array $state Scanner state.
	 * @param float $batch_started Batch start time.
	 * @param int   $batch_entries Number processed in this batch, by reference.
	 * @return array
	 */
	private function scan_current_directory( array $state, $batch_started, &$batch_entries ) {
		$current = $state['current'];
		$path    = (string) $current['path'];
		$offset  = absint( $current['offset'] );

		if ( ! OGSMI_Utils::path_is_within( $path, $state['root'] ) ) {
			$this->record_outside_root( $state, $path );
			$state['current'] = null;
			return $state;
		}

		$mtime = $this->directory_mtime( $path );
		if ( 0 < $offset && ! empty( $current['mtime'] ) && absint( $current['mtime'] ) !== $mtime ) {
			$this->mark_partial(
				$state,
				__( 'At least one directory changed while it was being scanned, so file totals may be approximate.', '1gbits-site-move-inspector' )
			);
		}
		$state['current']['mtime'] = $mtime;

		try {
			$iterator = new FilesystemIterator(
				$path,
				FilesystemIterator::SKIP_DOTS
				| FilesystemIterator::CURRENT_AS_FILEINFO
				| FilesystemIterator::KEY_AS_PATHNAME
			);
		} catch ( UnexpectedValueException $exception ) {
			$this->record_unreadable( $state, $path );
			$state['current'] = null;
			return $state;
		}

		$index       = 0;
		$paused      = false;
		$skip_folder = false;

		try {
			foreach ( $iterator as $file_info ) {
				if ( $index < $offset ) {
					if ( $index === $offset - 1 && ! empty( $current['last_entry_key'] ) ) {
						$boundary_key = md5( $this->comparison_path( $file_info->getPathname() ) );
						if ( ! hash_equals( (string) $current['last_entry_key'], $boundary_key ) ) {
							$this->mark_partial(
								$state,
								__( 'At least one directory changed while it was being scanned, so file totals may be approximate.', '1gbits-site-move-inspector' )
							);
						}
					}
					++$index;
					continue;
				}

				if ( $offset >= self::MAX_DIRECTORY_ENTRIES ) {
					$skip_folder = true;
					$this->mark_partial(
						$state,
						__( 'At least one directory exceeded the per-directory scan limit.', '1gbits-site-move-inspector' )
					);
					++$state['skipped_directory_count'];
					break;
				}

				$this->process_entry( $state, $file_info );

				++$offset;
				++$index;
				++$batch_entries;
				++$state['processed_entries'];
				$state['current']['offset']         = $offset;
				$state['current']['last_entry_key'] = md5( $this->comparison_path( $file_info->getPathname() ) );

				if ( $state['processed_entries'] >= self::MAX_ENTRIES ) {
					$paused = true;
					break;
				}

				if (
					$batch_entries >= self::MAX_BATCH_ENTRIES
					|| microtime( true ) - $batch_started >= self::MAX_BATCH_SECONDS
				) {
					$paused = true;
					break;
				}
			}
		} catch ( RuntimeException $exception ) {
			$this->record_unreadable( $state, $path );
			$skip_folder = true;
		}

		if ( ! $paused || $skip_folder ) {
			$state['current'] = null;
		}

		return $state;
	}

	/**
	 * Process one metadata entry.
	 *
	 * @param array       $state Scanner state, by reference.
	 * @param SplFileInfo $file_info Filesystem metadata.
	 */
	private function process_entry( array &$state, SplFileInfo $file_info ) {
		$path     = OGSMI_Utils::normalize_path( $file_info->getPathname() );
		$relative = OGSMI_Utils::relative_path( $path, $state['root'] );

		if ( $file_info->isLink() || is_link( $path ) ) {
			$this->record_symlink( $state, $path, $relative );
			return;
		}

		if ( $file_info->isDir() ) {
			$canonical = realpath( $path );
			if ( false === $canonical ) {
				$this->record_unreadable( $state, $path );
				return;
			}

			$canonical = OGSMI_Utils::normalize_path( $canonical );
			if ( ! OGSMI_Utils::path_is_within( $canonical, $state['root'] ) ) {
				$this->record_outside_root( $state, $path );
				return;
			}

			$visited_key = md5( $this->comparison_path( $canonical ) );
			if ( isset( $state['visited'][ $visited_key ] ) ) {
				return;
			}

			if ( count( $state['visited'] ) >= self::MAX_VISITED_DIRECTORIES ) {
				$this->mark_partial(
					$state,
					__( 'The filesystem scan reached its directory limit.', '1gbits-site-move-inspector' )
				);
				++$state['skipped_directory_count'];
				return;
			}

			$state['visited'][ $visited_key ] = true;
			$state['queue'][]                 = $canonical;
			++$state['directory_count'];
			return;
		}

		if ( ! $file_info->isFile() ) {
			++$state['special_file_count'];
			return;
		}

		try {
			$size = max( 0, (int) $file_info->getSize() );
		} catch ( RuntimeException $exception ) {
			$this->record_unreadable( $state, $path );
			return;
		}

		$category = $this->category_for( $relative, $state['markers'] );

		++$state['file_count'];
		$state['total_bytes'] += $size;
		++$state['categories'][ $category ]['file_count'];
		$state['categories'][ $category ]['bytes'] += $size;

		$this->add_top_file( $state, $relative, $size, $category );
	}

	/**
	 * Keep only the largest files discovered so far.
	 *
	 * @param array  $state Scanner state, by reference.
	 * @param string $relative Relative path.
	 * @param int    $size File size.
	 * @param string $category Category.
	 */
	private function add_top_file( array &$state, $relative, $size, $category ) {
		$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
		$extension = preg_match( '/^[a-z0-9]{1,12}$/', $extension ) ? $extension : '';

		$state['top_files'][] = array(
			'path'      => $relative,
			'bytes'     => $size,
			'extension' => $extension,
			'category'  => $category,
		);

		usort(
			$state['top_files'],
			static function ( $left, $right ) {
				return (int) $right['bytes'] <=> (int) $left['bytes'];
			}
		);

		if ( count( $state['top_files'] ) > self::TOP_FILE_LIMIT ) {
			$state['top_files'] = array_slice( $state['top_files'], 0, self::TOP_FILE_LIMIT );
		}
	}

	/**
	 * Record a symlink without following it.
	 *
	 * @param array  $state Scanner state, by reference.
	 * @param string $path Absolute link path.
	 * @param string $relative Relative link path.
	 */
	private function record_symlink( array &$state, $path, $relative ) {
		++$state['symlink_count'];
		$this->mark_partial(
			$state,
			__( 'Symbolic links were not followed, so file totals may be incomplete.', '1gbits-site-move-inspector' )
		);

		if ( count( $state['symlink_samples'] ) >= self::MAX_SAMPLE_PATHS ) {
			return;
		}

		$target = realpath( $path );
		if ( false === $target ) {
			$scope = 'unresolved';
		} elseif ( OGSMI_Utils::path_is_within( $target, $state['root'] ) ) {
			$scope = 'inside_root';
		} else {
			$scope = 'outside_root';
		}

		$state['symlink_samples'][] = array(
			'path'         => $relative,
			'target_scope' => $scope,
		);
	}

	/**
	 * Record an unreadable path.
	 *
	 * @param array  $state Scanner state, by reference.
	 * @param string $path Absolute path.
	 */
	private function record_unreadable( array &$state, $path ) {
		++$state['unreadable_count'];
		if ( count( $state['unreadable_samples'] ) < self::MAX_SAMPLE_PATHS ) {
			$state['unreadable_samples'][] = OGSMI_Utils::relative_path( $path, $state['root'] );
		}
	}

	/**
	 * Record a path that resolves beyond the WordPress root.
	 *
	 * @param array  $state Scanner state, by reference.
	 * @param string $path Absolute path.
	 */
	private function record_outside_root( array &$state, $path ) {
		++$state['outside_root_count'];
		if ( count( $state['outside_root_samples'] ) < self::MAX_SAMPLE_PATHS ) {
			$relative                        = OGSMI_Utils::relative_path( $path, $state['root'] );
			$state['outside_root_samples'][] = '' === $relative ? '[outside-root]' : $relative;
		}
	}

	/**
	 * Return whether processing should yield to the next request.
	 *
	 * @param array $state Scanner state.
	 * @param float $batch_started Batch start time.
	 * @param int   $batch_entries Entries processed in this request.
	 * @return bool
	 */
	private function limit_reached( array $state, $batch_started, $batch_entries ) {
		return $state['processed_entries'] >= self::MAX_ENTRIES
			|| $state['elapsed_seconds'] >= self::MAX_TOTAL_SECONDS
			|| $batch_entries >= self::MAX_BATCH_ENTRIES
			|| microtime( true ) - $batch_started >= self::MAX_BATCH_SECONDS;
	}

	/**
	 * Mark the state incomplete.
	 *
	 * @param array  $state Scanner state, by reference.
	 * @param string $reason Reason.
	 */
	private function mark_partial( array &$state, $reason ) {
		$state['partial'] = true;
		if ( ! in_array( $reason, $state['partial_reasons'], true ) ) {
			$state['partial_reasons'][] = $reason;
		}
	}

	/**
	 * Stop processing while preserving collected metadata.
	 *
	 * @param array $state Scanner state, by reference.
	 */
	private function finish_early( array &$state ) {
		$state['queue']     = array();
		$state['current']   = null;
		$state['completed'] = true;
	}

	/**
	 * Build file categories in display order.
	 *
	 * @return array
	 */
	private function empty_categories() {
		return array(
			'uploads' => array(
				'id'         => 'uploads',
				'label'      => __( 'Uploads', '1gbits-site-move-inspector' ),
				'file_count' => 0,
				'bytes'      => 0,
			),
			'plugins' => array(
				'id'         => 'plugins',
				'label'      => __( 'Plugins', '1gbits-site-move-inspector' ),
				'file_count' => 0,
				'bytes'      => 0,
			),
			'themes'  => array(
				'id'         => 'themes',
				'label'      => __( 'Themes', '1gbits-site-move-inspector' ),
				'file_count' => 0,
				'bytes'      => 0,
			),
			'core'    => array(
				'id'         => 'core',
				'label'      => __( 'WordPress core', '1gbits-site-move-inspector' ),
				'file_count' => 0,
				'bytes'      => 0,
			),
			'other'   => array(
				'id'         => 'other',
				'label'      => __( 'Other files', '1gbits-site-move-inspector' ),
				'file_count' => 0,
				'bytes'      => 0,
			),
		);
	}

	/**
	 * Categorize a relative path.
	 *
	 * @param string $relative Relative path.
	 * @param array  $markers Relative special directories.
	 * @return string
	 */
	private function category_for( $relative, array $markers ) {
		$relative = ltrim( OGSMI_Utils::normalize_path( $relative ), '/' );

		foreach ( array( 'uploads', 'plugins', 'themes' ) as $category ) {
			foreach ( (array) ( $markers[ $category ] ?? array() ) as $marker ) {
				if ( $this->relative_starts_with( $relative, $marker ) ) {
					return $category;
				}
			}
		}

		foreach ( (array) ( $markers['core'] ?? array( '' ) ) as $core_marker ) {
			$core_relative = $this->path_relative_to_marker( $relative, $core_marker );
			if (
				false !== $core_relative
				&& (
					$this->relative_starts_with( $core_relative, 'wp-admin' )
					|| $this->relative_starts_with( $core_relative, 'wp-includes' )
					|| 1 === preg_match( '/^wp-[^\/]+\.php$/', $core_relative )
					|| in_array( $core_relative, array( 'index.php', 'xmlrpc.php', 'license.txt', 'readme.html' ), true )
				)
			) {
				return 'core';
			}
		}

		return 'other';
	}

	/**
	 * Return a path relative to a marker, or false when it is outside.
	 *
	 * An empty marker represents the scan root, preserving the default layout.
	 *
	 * @param string $path Relative scan path.
	 * @param string $marker Relative directory marker.
	 * @return string|false
	 */
	private function path_relative_to_marker( $path, $marker ) {
		$path   = trim( OGSMI_Utils::normalize_path( $path ), '/' );
		$marker = trim( OGSMI_Utils::normalize_path( $marker ), '/' );

		if ( '' === $marker ) {
			return $path;
		}

		if ( ! $this->relative_starts_with( $path, $marker ) ) {
			return false;
		}

		return $path === $marker ? '' : ltrim( substr( $path, strlen( $marker ) ), '/' );
	}

	/**
	 * Test a relative-directory boundary.
	 *
	 * @param string $path Relative path.
	 * @param string $prefix Relative directory.
	 * @return bool
	 */
	private function relative_starts_with( $path, $prefix ) {
		$path   = trim( OGSMI_Utils::normalize_path( $path ), '/' );
		$prefix = trim( OGSMI_Utils::normalize_path( $prefix ), '/' );

		if ( '' === $prefix ) {
			return false;
		}

		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$path   = strtolower( $path );
			$prefix = strtolower( $prefix );
		}

		return $path === $prefix || 0 === strpos( $path, $prefix . '/' );
	}

	/**
	 * Resolve a marker only when it is within the scan root.
	 *
	 * @param string $path Marker path.
	 * @param string $root Scan root.
	 * @return string
	 */
	private function resolve_marker( $path, $root ) {
		if ( '' === (string) $path ) {
			return array(
				'relative' => '',
				'excluded' => false,
			);
		}

		$canonical = realpath( $path );
		if ( false === $canonical ) {
			return array(
				'relative' => '',
				'excluded' => file_exists( $path ) || is_link( $path ),
			);
		}

		if ( ! OGSMI_Utils::path_is_within( $canonical, $root ) ) {
			return array(
				'relative' => '',
				'excluded' => true,
			);
		}

		return array(
			'relative' => OGSMI_Utils::relative_path( $canonical, $root ),
			'excluded' => false,
		);
	}

	/**
	 * Return a translated, human-readable marker label.
	 *
	 * @param string $marker_id Marker ID.
	 * @return string
	 */
	private function marker_label( $marker_id ) {
		$labels = array(
			'core'    => __( 'WordPress core', '1gbits-site-move-inspector' ),
			'plugins' => __( 'plugins', '1gbits-site-move-inspector' ),
			'themes'  => __( 'themes', '1gbits-site-move-inspector' ),
			'uploads' => __( 'uploads', '1gbits-site-move-inspector' ),
		);

		return $labels[ $marker_id ] ?? __( 'custom', '1gbits-site-move-inspector' );
	}

	/**
	 * Return a directory modification timestamp without emitting warnings.
	 *
	 * @param string $path Directory path.
	 * @return int
	 */
	private function directory_mtime( $path ) {
		try {
			$directory = new SplFileInfo( $path );
			return max( 0, (int) $directory->getMTime() );
		} catch ( RuntimeException $exception ) {
			return 0;
		}
	}

	/**
	 * Normalize casing for a visited-set key.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function comparison_path( $path ) {
		$path = OGSMI_Utils::normalize_path( $path );

		return '\\' === DIRECTORY_SEPARATOR ? strtolower( $path ) : $path;
	}
}
