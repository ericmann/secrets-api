#!/usr/bin/env php
<?php
/**
 * Generates the API reference under docs/reference/ from source docblocks.
 *
 * Scans the plugin's PHP source with PHP's tokenizer, collects functions,
 * classes, public methods, hooks, and WP-CLI commands together with their
 * docblocks, and writes four Markdown files:
 *
 *   docs/reference/functions.md
 *   docs/reference/classes.md
 *   docs/reference/hooks.md
 *   docs/reference/wp-cli.md
 *
 * Output is deterministic: everything is sorted, nothing carries a timestamp
 * or a line number, so a diff of a generated file reflects a change in the
 * source and nothing else.
 *
 * Usage:
 *   php bin/gen-reference.php          Regenerate the four files.
 *   php bin/gen-reference.php --check  Exit 1 if any committed file is stale.
 *
 * No dependencies beyond PHP itself. Runs on PHP 7.4 and later.
 *
 * @package SecretsAPI
 */

/**
 * Reads the plugin source and renders the reference documents.
 */
final class Secrets_API_Reference_Generator {

	/**
	 * Files and directories to scan, relative to the repository root.
	 *
	 * @var string[]
	 */
	const SOURCES = array( 'secrets-api.php', 'src', 'plugin', 'cli' );

	/**
	 * Where the generated files are written, relative to the repository root.
	 *
	 * @var string
	 */
	const OUTPUT_DIR = 'docs/reference';

	/**
	 * Hook-firing functions whose first argument names the hook.
	 *
	 * @var array<string, string>
	 */
	const HOOK_FUNCTIONS = array(
		'do_action'                => 'action',
		'do_action_ref_array'      => 'action',
		'do_action_deprecated'     => 'action',
		'apply_filters'            => 'filter',
		'apply_filters_ref_array'  => 'filter',
		'apply_filters_deprecated' => 'filter',
	);

	/**
	 * Absolute path of the repository root, without a trailing slash.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Top-level functions, keyed by name.
	 *
	 * @var array<string, array>
	 */
	private $functions = array();

	/**
	 * Classes and interfaces, keyed by name.
	 *
	 * @var array<string, array>
	 */
	private $classes = array();

	/**
	 * Hook call sites, keyed by hook name.
	 *
	 * @var array<string, array>
	 */
	private $hooks = array();

	/**
	 * WP-CLI command registrations: command name => class name.
	 *
	 * @var array<string, string>
	 */
	private $cli_commands = array();

	/**
	 * Entry point.
	 *
	 * @param string[] $argv Command-line arguments, including the script name.
	 *
	 * @return int Exit code.
	 */
	public static function main( array $argv ) {
		$check     = in_array( '--check', $argv, true );
		$generator = new self( dirname( __DIR__ ) );

		return $generator->run( $check );
	}

	/**
	 * Constructor.
	 *
	 * @param string $root Absolute path of the repository root.
	 */
	public function __construct( $root ) {
		$this->root = rtrim( $root, '/' );
	}

	/**
	 * Scans the source and either writes the reference or checks it.
	 *
	 * @param bool $check When true, compare against the committed files instead
	 *                    of writing, and return 1 if any of them is stale.
	 *
	 * @return int Exit code.
	 */
	public function run( $check ) {
		foreach ( $this->source_files() as $file ) {
			$this->scan_file( $file );
		}

		$outputs = array(
			'functions.md' => $this->render_functions(),
			'classes.md'   => $this->render_classes(),
			'hooks.md'     => $this->render_hooks(),
			'wp-cli.md'    => $this->render_cli(),
		);

		$stale = array();

		foreach ( $outputs as $name => $content ) {
			$path = $this->root . '/' . self::OUTPUT_DIR . '/' . $name;

			if ( $check ) {
				$existing = is_file( $path ) ? file_get_contents( $path ) : null;

				if ( $existing !== $content ) {
					$stale[] = self::OUTPUT_DIR . '/' . $name;
					$this->report_difference( self::OUTPUT_DIR . '/' . $name, $existing, $content );
				}

				continue;
			}

			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0755, true );
			}

			file_put_contents( $path, $content );
			fwrite( STDOUT, 'Wrote ' . self::OUTPUT_DIR . '/' . $name . "\n" );
		}

		if ( $check ) {
			if ( $stale ) {
				fwrite( STDERR, "\nGenerated reference is out of date: " . implode( ', ', $stale ) . "\n" );
				fwrite( STDERR, "Run `php bin/gen-reference.php` and commit the result.\n" );

				return 1;
			}

			fwrite( STDOUT, self::OUTPUT_DIR . " is up to date.\n" );
		}

		return 0;
	}

	/**
	 * Prints the first line at which a committed file and its regenerated form differ.
	 *
	 * @param string      $name     Repository-relative file name.
	 * @param string|null $existing Committed content, or null if the file is missing.
	 * @param string      $fresh    Regenerated content.
	 *
	 * @return void
	 */
	private function report_difference( $name, $existing, $fresh ) {
		if ( null === $existing ) {
			fwrite( STDERR, "{$name}: missing\n" );

			return;
		}

		$old = explode( "\n", $existing );
		$new = explode( "\n", $fresh );
		$max = max( count( $old ), count( $new ) );

		for ( $i = 0; $i < $max; $i++ ) {
			$a = isset( $old[ $i ] ) ? $old[ $i ] : null;
			$b = isset( $new[ $i ] ) ? $new[ $i ] : null;

			if ( $a !== $b ) {
				fwrite( STDERR, sprintf( "%s: first difference at line %d\n", $name, $i + 1 ) );
				fwrite( STDERR, '  committed: ' . ( null === $a ? '<end of file>' : $a ) . "\n" );
				fwrite( STDERR, '  generated: ' . ( null === $b ? '<end of file>' : $b ) . "\n" );

				return;
			}
		}
	}

	/**
	 * Lists every PHP file under the configured sources, sorted by path.
	 *
	 * @return string[] Repository-relative paths.
	 */
	private function source_files() {
		$files = array();

		foreach ( self::SOURCES as $source ) {
			$absolute = $this->root . '/' . $source;

			if ( is_file( $absolute ) ) {
				$files[] = $source;

				continue;
			}

			if ( ! is_dir( $absolute ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $absolute, FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $entry ) {
				if ( 'php' === strtolower( $entry->getExtension() ) ) {
					$files[] = substr( $entry->getPathname(), strlen( $this->root ) + 1 );
				}
			}
		}

		sort( $files, SORT_STRING );

		return $files;
	}

	/**
	 * Tokenizes one file and records what it declares.
	 *
	 * @param string $file Repository-relative path.
	 *
	 * @return void
	 */
	private function scan_file( $file ) {
		$tokens = token_get_all( file_get_contents( $this->root . '/' . $file ) );
		$count  = count( $tokens );

		$depth       = 0;
		$pending_doc = null;
		$modifiers   = array();
		$class_stack = array();
		$previous    = null;

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( is_string( $token ) ) {
				if ( '{' === $token ) {
					++$depth;
				} elseif ( '}' === $token ) {
					--$depth;

					while ( $class_stack && $depth < end( $class_stack )['depth'] ) {
						array_pop( $class_stack );
					}
				}

				if ( ';' === $token || '{' === $token || '}' === $token ) {
					$modifiers = array();
				}

				$pending_doc = null;
				$previous    = $token;

				continue;
			}

			$id   = $token[0];
			$text = $token[1];

			if ( T_WHITESPACE === $id || T_COMMENT === $id ) {
				continue;
			}

			if ( T_DOC_COMMENT === $id ) {
				$pending_doc = $text;
				$previous    = $token;

				continue;
			}

			// Braces opened inside strings still close with a plain '}'.
			if ( T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
				++$depth;
				$previous = $token;

				continue;
			}

			// PHP 8 attributes: skip to the closing bracket without disturbing the docblock.
			if ( defined( 'T_ATTRIBUTE' ) && T_ATTRIBUTE === $id ) {
				$i        = $this->skip_attribute( $tokens, $i );
				$previous = $token;

				continue;
			}

			if ( in_array( $id, array( T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL ), true ) ) {
				$modifiers[] = strtolower( $text );
				$previous    = $token;

				continue;
			}

			if ( T_CLASS === $id || T_INTERFACE === $id || T_TRAIT === $id ) {
				$is_reference = is_array( $previous ) && in_array( $previous[0], array( T_DOUBLE_COLON, T_NEW ), true );
				$name_index   = $this->next_significant( $tokens, $i );

				if ( ! $is_reference && false !== $name_index && is_array( $tokens[ $name_index ] ) && T_STRING === $tokens[ $name_index ][0] ) {
					$i = $this->record_class( $tokens, $i, $name_index, $file, $pending_doc, $modifiers, $class_stack, $depth );
				}

				$pending_doc = null;
				$modifiers   = array();
				$previous    = $token;

				continue;
			}

			if ( T_FUNCTION === $id ) {
				$name_index = $this->next_significant( $tokens, $i );

				// Reserved words are legal method names (`list()`, for one) and tokenize as
				// their own token type rather than T_STRING, so test the text, not the type.
				if ( false !== $name_index && is_array( $tokens[ $name_index ] ) && preg_match( '/^[A-Za-z_]\w*$/', $tokens[ $name_index ][1] ) ) {
					$this->record_function( $tokens, $i, $name_index, $file, $pending_doc, $modifiers, $class_stack, $depth );
				}

				$pending_doc = null;
				$modifiers   = array();
				$previous    = $token;

				continue;
			}

			if ( T_CONST === $id && $class_stack && end( $class_stack )['depth'] === $depth ) {
				$this->record_constant( $tokens, $i, $file, $pending_doc, $modifiers, $class_stack );
				$pending_doc = null;
				$modifiers   = array();
				$previous    = $token;

				continue;
			}

			if ( T_STRING === $id && isset( self::HOOK_FUNCTIONS[ $text ] ) && $this->is_function_call( $tokens, $i, $previous ) ) {
				$this->record_hook( $tokens, $i, $file, $pending_doc, self::HOOK_FUNCTIONS[ $text ] );
			}

			if ( T_STRING === $id && 'WP_CLI' === $text ) {
				$this->record_cli_registration( $tokens, $i );
			}

			$pending_doc = null;
			$previous    = $token;
		}
	}

	/**
	 * Returns the index of the closing bracket of a PHP 8 attribute.
	 *
	 * @param array $tokens Token stream.
	 * @param int   $start  Index of the T_ATTRIBUTE token.
	 *
	 * @return int
	 */
	private function skip_attribute( array $tokens, $start ) {
		$level = 1;
		$count = count( $tokens );

		for ( $i = $start + 1; $i < $count; $i++ ) {
			if ( '[' === $tokens[ $i ] ) {
				++$level;
			} elseif ( ']' === $tokens[ $i ] ) {
				--$level;

				if ( 0 === $level ) {
					return $i;
				}
			}
		}

		return $count - 1;
	}

	/**
	 * Returns the index of the next token that is not whitespace or a comment.
	 *
	 * @param array $tokens Token stream.
	 * @param int   $from   Index to search after.
	 *
	 * @return int|false
	 */
	private function next_significant( array $tokens, $from ) {
		$count = count( $tokens );

		for ( $i = $from + 1; $i < $count; $i++ ) {
			if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			return $i;
		}

		return false;
	}

	/**
	 * Whether a T_STRING token is a plain function call rather than a method or a definition.
	 *
	 * @param array $tokens   Token stream.
	 * @param int   $index    Index of the T_STRING token.
	 * @param mixed $previous The previous significant token.
	 *
	 * @return bool
	 */
	private function is_function_call( array $tokens, $index, $previous ) {
		if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
			return false;
		}

		$next = $this->next_significant( $tokens, $index );

		return false !== $next && '(' === $tokens[ $next ];
	}

	/**
	 * Concatenates tokens from one index to another, collapsing whitespace.
	 *
	 * @param array $tokens Token stream.
	 * @param int   $from   First index, inclusive.
	 * @param int   $to     Last index, inclusive.
	 *
	 * @return string
	 */
	private function slice_text( array $tokens, $from, $to ) {
		$text = '';

		for ( $i = $from; $i <= $to; $i++ ) {
			$text .= is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
		}

		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Finds the index of the token that closes a parenthesised group.
	 *
	 * @param array $tokens Token stream.
	 * @param int   $open   Index of the opening '('.
	 *
	 * @return int
	 */
	private function matching_paren( array $tokens, $open ) {
		$level = 0;
		$count = count( $tokens );

		for ( $i = $open; $i < $count; $i++ ) {
			if ( '(' === $tokens[ $i ] ) {
				++$level;
			} elseif ( ')' === $tokens[ $i ] ) {
				--$level;

				if ( 0 === $level ) {
					return $i;
				}
			}
		}

		return $count - 1;
	}

	/**
	 * Records a class or interface declaration and returns the index of its opening brace.
	 *
	 * @param array       $tokens      Token stream.
	 * @param int         $keyword     Index of the class/interface/trait keyword.
	 * @param int         $name_index  Index of the name token.
	 * @param string      $file        Repository-relative path.
	 * @param string|null $doc         Preceding docblock, if any.
	 * @param string[]    $modifiers   Modifiers seen before the keyword.
	 * @param array       $class_stack Class context stack, by reference.
	 * @param int         $depth       Current brace depth, by reference.
	 *
	 * @return int
	 */
	private function record_class( array $tokens, $keyword, $name_index, $file, $doc, array $modifiers, array &$class_stack, &$depth ) {
		$name  = $tokens[ $name_index ][1];
		$count = count( $tokens );
		$open  = $name_index;

		for ( $i = $name_index + 1; $i < $count; $i++ ) {
			if ( '{' === $tokens[ $i ] ) {
				$open = $i;

				break;
			}
		}

		$heritage   = $this->slice_text( $tokens, $name_index + 1, $open - 1 );
		$extends    = '';
		$implements = array();

		if ( preg_match( '/\bextends\s+([\w\\\\]+)/', $heritage, $m ) ) {
			$extends = $m[1];
		}

		if ( preg_match( '/\bimplements\s+([\w\\\\,\s]+)$/', $heritage, $m ) ) {
			$implements = array_map( 'trim', explode( ',', $m[1] ) );
		}

		$kind = strtolower( $tokens[ $keyword ][1] );

		if ( 'class' === $kind && $modifiers ) {
			$kind = implode( ' ', array_intersect( array( 'final', 'abstract' ), $modifiers ) ) . ' class';
		}

		$this->classes[ $name ] = array(
			'name'       => $name,
			'kind'       => trim( $kind ),
			'extends'    => $extends,
			'implements' => $implements,
			'file'       => $file,
			'doc'        => $this->parse_docblock( $doc ),
			'constants'  => array(),
			'methods'    => array(),
		);

		++$depth;

		$class_stack[] = array(
			'name'  => $name,
			'depth' => $depth,
		);

		return $open;
	}

	/**
	 * Records a function or method declaration.
	 *
	 * @param array       $tokens      Token stream.
	 * @param int         $keyword     Index of the function keyword.
	 * @param int         $name_index  Index of the name token.
	 * @param string      $file        Repository-relative path.
	 * @param string|null $doc         Preceding docblock, if any.
	 * @param string[]    $modifiers   Modifiers seen before the keyword.
	 * @param array       $class_stack Class context stack.
	 * @param int         $depth       Current brace depth.
	 *
	 * @return void
	 */
	private function record_function( array $tokens, $keyword, $name_index, $file, $doc, array $modifiers, array $class_stack, $depth ) {
		$in_class = $class_stack && end( $class_stack )['depth'] === $depth;

		if ( ! $in_class && ( 0 !== $depth || $class_stack ) ) {
			return; // A nested function or a closure inside a body.
		}

		$paren = $this->next_significant( $tokens, $name_index );

		if ( false === $paren || '(' !== $tokens[ $paren ] ) {
			return;
		}

		$close = $this->matching_paren( $tokens, $paren );
		$end   = $close;
		$count = count( $tokens );

		// Include a return type, if declared, up to the body or the terminating semicolon.
		for ( $i = $close + 1; $i < $count; $i++ ) {
			if ( '{' === $tokens[ $i ] || ';' === $tokens[ $i ] ) {
				$end = $i - 1;

				break;
			}
		}

		$signature = $this->slice_text( $tokens, $keyword, $end );
		$signature = preg_replace( '/^function\s+/', 'function ', $signature );

		if ( $in_class ) {
			$visibility = 'public';

			foreach ( array( 'private', 'protected', 'public' ) as $candidate ) {
				if ( in_array( $candidate, $modifiers, true ) ) {
					$visibility = $candidate;
				}
			}

			$prefix = array_values( array_intersect( array( 'abstract', 'final', 'public', 'protected', 'private', 'static' ), $modifiers ) );

			if ( ! in_array( $visibility, $prefix, true ) ) {
				array_unshift( $prefix, $visibility );
			}

			$class = end( $class_stack )['name'];

			$this->classes[ $class ]['methods'][ $tokens[ $name_index ][1] ] = array(
				'name'       => $tokens[ $name_index ][1],
				'visibility' => $visibility,
				'static'     => in_array( 'static', $modifiers, true ),
				'signature'  => implode( ' ', $prefix ) . ' ' . $signature,
				'file'       => $file,
				'doc'        => $this->parse_docblock( $doc ),
			);

			return;
		}

		$this->functions[ $tokens[ $name_index ][1] ] = array(
			'name'      => $tokens[ $name_index ][1],
			'signature' => $signature,
			'file'      => $file,
			'doc'       => $this->parse_docblock( $doc ),
		);
	}

	/**
	 * Records a class constant.
	 *
	 * @param array       $tokens      Token stream.
	 * @param int         $keyword     Index of the const keyword.
	 * @param string      $file        Repository-relative path.
	 * @param string|null $doc         Preceding docblock, if any.
	 * @param string[]    $modifiers   Modifiers seen before the keyword.
	 * @param array       $class_stack Class context stack.
	 *
	 * @return void
	 */
	private function record_constant( array $tokens, $keyword, $file, $doc, array $modifiers, array $class_stack ) {
		$name_index = $this->next_significant( $tokens, $keyword );

		if ( false === $name_index || ! is_array( $tokens[ $name_index ] ) ) {
			return;
		}

		$count = count( $tokens );
		$end   = $name_index;

		for ( $i = $name_index; $i < $count; $i++ ) {
			if ( ';' === $tokens[ $i ] ) {
				$end = $i - 1;

				break;
			}
		}

		$declaration = $this->slice_text( $tokens, $name_index, $end );
		$value       = '';

		if ( preg_match( '/^\w+\s*=\s*(.*)$/s', $declaration, $m ) ) {
			$value = $m[1];
		}

		$visibility = 'public';

		foreach ( array( 'private', 'protected' ) as $candidate ) {
			if ( in_array( $candidate, $modifiers, true ) ) {
				$visibility = $candidate;
			}
		}

		$class = end( $class_stack )['name'];

		$this->classes[ $class ]['constants'][ $tokens[ $name_index ][1] ] = array(
			'name'       => $tokens[ $name_index ][1],
			'value'      => $value,
			'visibility' => $visibility,
			'file'       => $file,
			'doc'        => $this->parse_docblock( $doc ),
		);
	}

	/**
	 * Records a do_action() or apply_filters() call site.
	 *
	 * @param array       $tokens Token stream.
	 * @param int         $index  Index of the function name token.
	 * @param string      $file   Repository-relative path.
	 * @param string|null $doc    Preceding docblock, if any.
	 * @param string      $type   'action' or 'filter'.
	 *
	 * @return void
	 */
	private function record_hook( array $tokens, $index, $file, $doc, $type ) {
		$open  = $this->next_significant( $tokens, $index );
		$close = $this->matching_paren( $tokens, $open );

		// The first argument runs to the first top-level comma.
		$level = 0;
		$end   = $close - 1;

		for ( $i = $open + 1; $i < $close; $i++ ) {
			if ( '(' === $tokens[ $i ] || '[' === $tokens[ $i ] ) {
				++$level;
			} elseif ( ')' === $tokens[ $i ] || ']' === $tokens[ $i ] ) {
				--$level;
			} elseif ( ',' === $tokens[ $i ] && 0 === $level ) {
				$end = $i - 1;

				break;
			}
		}

		$first = $this->slice_text( $tokens, $open + 1, $end );

		if ( preg_match( '/^([\'"])(.*)\1$/s', $first, $m ) ) {
			$name = $m[2];
		} else {
			$name = $first; // A dynamic hook name; keep the expression.
		}

		$documented_in = null;

		if ( null !== $doc && preg_match( '/This (?:action|filter) is documented in\s+(\S+)/', $doc, $m ) ) {
			$documented_in = rtrim( $m[1], '.' );
			$doc           = null;
		}

		if ( ! isset( $this->hooks[ $name ] ) ) {
			$this->hooks[ $name ] = array(
				'name'  => $name,
				'type'  => $type,
				'doc'   => null,
				'sites' => array(),
			);
		}

		if ( null !== $doc && null === $this->hooks[ $name ]['doc'] ) {
			$this->hooks[ $name ]['doc']      = $this->parse_docblock( $doc );
			$this->hooks[ $name ]['doc_file'] = $file;
		}

		$this->hooks[ $name ]['sites'][] = array(
			'file'          => $file,
			'documented_in' => $documented_in,
		);
	}

	/**
	 * Records a WP_CLI::add_command( 'name', 'Class_Name' ) registration.
	 *
	 * @param array $tokens Token stream.
	 * @param int   $index  Index of the WP_CLI token.
	 *
	 * @return void
	 */
	private function record_cli_registration( array $tokens, $index ) {
		$colon = $this->next_significant( $tokens, $index );

		if ( false === $colon || ! is_array( $tokens[ $colon ] ) || T_DOUBLE_COLON !== $tokens[ $colon ][0] ) {
			return;
		}

		$method = $this->next_significant( $tokens, $colon );

		if ( false === $method || ! is_array( $tokens[ $method ] ) || 'add_command' !== $tokens[ $method ][1] ) {
			return;
		}

		$open = $this->next_significant( $tokens, $method );

		if ( false === $open || '(' !== $tokens[ $open ] ) {
			return;
		}

		$close = $this->matching_paren( $tokens, $open );
		$args  = $this->slice_text( $tokens, $open + 1, $close - 1 );

		if ( preg_match( '/^([\'"])([^\'"]+)\1\s*,\s*([\'"])([^\'"]+)\3/', $args, $m ) ) {
			$this->cli_commands[ $m[2] ] = ltrim( $m[4], '\\' );
		}
	}

	/**
	 * Parses a docblock into summary, description, sections, and tags.
	 *
	 * @param string|null $raw The docblock text, including delimiters.
	 *
	 * @return array {
	 *     @type string   $summary     First paragraph, joined onto one line.
	 *     @type string   $description Remaining free text, hard wraps preserved.
	 *     @type array    $sections    Text under `## HEADING` lines, keyed by heading.
	 *     @type string[] $since       Every @since value.
	 *     @type array    $params      @param entries: type, name, description.
	 *     @type string   $return      @return type and description, or ''.
	 *     @type string[] $throws      @throws entries.
	 *     @type array    $tags        Every other tag: name => values.
	 * }
	 */
	private function parse_docblock( $raw ) {
		$parsed = array(
			'summary'     => '',
			'description' => '',
			'sections'    => array(),
			'since'       => array(),
			'params'      => array(),
			'return'      => '',
			'throws'      => array(),
			'tags'        => array(),
		);

		if ( null === $raw ) {
			return $parsed;
		}

		$body  = preg_replace( '#^/\*\*|\*/$#', '', trim( $raw ) );
		$lines = array();

		foreach ( explode( "\n", $body ) as $line ) {
			$lines[] = rtrim( preg_replace( '/^\s*\*\s?/', '', $line ) );
		}

		$free = array();
		$tags = array();

		foreach ( $lines as $line ) {
			if ( preg_match( '/^@(\w+)\s*(.*)$/', $line, $m ) ) {
				$tags[] = array( $m[1], $m[2] );

				continue;
			}

			if ( $tags && '' !== trim( $line ) && '}' !== trim( $line ) ) {
				// A continuation line of the previous tag.
				$last             = count( $tags ) - 1;
				$tags[ $last ][1] = trim( $tags[ $last ][1] . ' ' . trim( $line ) );

				continue;
			}

			if ( ! $tags ) {
				$free[] = $line;
			}
		}

		// Split free text into the main text and any `## HEADING` sections.
		$main    = array();
		$current = null;

		foreach ( $free as $line ) {
			if ( preg_match( '/^##\s+(.+)$/', $line, $m ) ) {
				$current                        = strtoupper( trim( $m[1] ) );
				$parsed['sections'][ $current ] = array();

				continue;
			}

			if ( null === $current ) {
				$main[] = $line;
			} else {
				$parsed['sections'][ $current ][] = $line;
			}
		}

		$main = $this->trim_blank_lines( $main );

		$summary_lines = array();

		while ( $main && '' !== trim( $main[0] ) ) {
			$summary_lines[] = trim( array_shift( $main ) );
		}

		$parsed['summary']     = implode( ' ', $summary_lines );
		$parsed['description'] = implode( "\n", $this->trim_blank_lines( $main ) );

		foreach ( $tags as $tag ) {
			list( $tag_name, $value ) = $tag;

			switch ( $tag_name ) {
				case 'since':
					$parsed['since'][] = $value;
					break;

				case 'param':
					if ( preg_match( '/^(\S+)\s+(&?\.{0,3}\$\w+)\s*(.*)$/s', $value, $m ) ) {
						$parsed['params'][] = array(
							'type'        => $m[1],
							'name'        => $m[2],
							'description' => trim( $m[3] ),
						);
					} else {
						$parsed['params'][] = array(
							'type'        => '',
							'name'        => '',
							'description' => $value,
						);
					}
					break;

				case 'return':
					$parsed['return'] = $value;
					break;

				case 'throws':
					$parsed['throws'][] = $value;
					break;

				default:
					$parsed['tags'][ $tag_name ][] = $value;
			}
		}

		return $parsed;
	}

	/**
	 * Removes leading and trailing blank lines from a list of lines.
	 *
	 * @param string[] $lines Lines.
	 *
	 * @return string[]
	 */
	private function trim_blank_lines( array $lines ) {
		while ( $lines && '' === trim( $lines[0] ) ) {
			array_shift( $lines );
		}

		while ( $lines && '' === trim( end( $lines ) ) ) {
			array_pop( $lines );
		}

		return $lines;
	}

	/**
	 * Escapes text for use inside a Markdown table cell.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	private function cell( $text ) {
		return str_replace( '|', '\\|', $this->prose( trim( preg_replace( '/\s+/', ' ', $text ) ) ) );
	}

	/**
	 * Escapes angle brackets in prose so `<name>` survives Markdown rendering.
	 *
	 * Docblocks write placeholders like `<name>` and `<namespace>` in plain text.
	 * Markdown renderers treat those as HTML tags and drop them. Text inside
	 * backtick code spans is left alone, since it is already literal.
	 *
	 * @param string $text Prose that may contain backtick code spans.
	 *
	 * @return string
	 */
	private function prose( $text ) {
		$parts = preg_split( '/(`[^`\n]*`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );

		foreach ( $parts as $index => $part ) {
			if ( 0 === $index % 2 ) {
				$parts[ $index ] = str_replace( array( '<', '>' ), array( '\\<', '\\>' ), $part );
			}
		}

		return implode( '', $parts );
	}

	/**
	 * Wraps text in backticks, escaping pipes so it survives a table cell.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	private function code_cell( $text ) {
		$text = trim( $text );

		return '' === $text ? '' : '`' . str_replace( '|', '\\|', $text ) . '`';
	}

	/**
	 * Builds the frontmatter and generated-file notice that opens every output.
	 *
	 * @param string $title       Page title.
	 * @param string $description One-line description.
	 *
	 * @return string
	 */
	private function header( $title, $description ) {
		return "---\n"
			. 'title: "' . $title . "\"\n"
			. 'description: "' . $description . "\"\n"
			. "---\n\n"
			. "<!--\n"
			. "  GENERATED FILE. Do not edit by hand.\n"
			. "  Produced by bin/gen-reference.php from docblocks in the PHP source.\n"
			. "  Edit the docblock, then run `php bin/gen-reference.php`. CI fails when this\n"
			. "  file no longer matches the source.\n"
			. "-->\n\n";
	}

	/**
	 * Renders the Source line for an item.
	 *
	 * @param string $file Repository-relative path.
	 *
	 * @return string
	 */
	private function source_line( $file ) {
		return '**Source:** [`' . $file . '`](../../' . $file . ")\n";
	}

	/**
	 * Renders the shared body of a function or method: summary, description,
	 * signature, parameters, return, throws, since.
	 *
	 * @param array  $item      Function or method record.
	 * @param string $signature Signature to show in the code block.
	 *
	 * @return string
	 */
	private function render_callable_body( array $item, $signature ) {
		$doc = $item['doc'];
		$out = '';

		if ( '' !== $doc['summary'] ) {
			$out .= $this->prose( $doc['summary'] ) . "\n\n";
		} else {
			$out .= "*No docblock.*\n\n";
		}

		if ( '' !== $doc['description'] ) {
			$out .= $this->prose( $doc['description'] ) . "\n\n";
		}

		$out .= "```php\n" . $signature . "\n```\n\n";

		if ( $doc['params'] ) {
			$out .= "| Parameter | Type | Description |\n|---|---|---|\n";

			foreach ( $doc['params'] as $param ) {
				$out .= '| ' . $this->code_cell( $param['name'] ) . ' | ' . $this->code_cell( $param['type'] ) . ' | ' . $this->cell( $param['description'] ) . " |\n";
			}

			$out .= "\n";
		}

		if ( '' !== $doc['return'] ) {
			$out .= '**Returns:** ' . $this->render_typed_text( $doc['return'] ) . "\n\n";
		}

		foreach ( $doc['throws'] as $throws ) {
			$out .= '**Throws:** ' . $this->render_typed_text( $throws ) . "\n\n";
		}

		if ( $doc['since'] ) {
			$out .= '**Since:** ' . implode( ', ', $doc['since'] ) . "\n\n";
		}

		return $out;
	}

	/**
	 * Renders a "Type description" tag value with the type in backticks.
	 *
	 * @param string $value Tag value, type first.
	 *
	 * @return string
	 */
	private function render_typed_text( $value ) {
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );

		if ( preg_match( '/^(\S+)\s*(.*)$/s', $value, $m ) ) {
			return '`' . $m[1] . '`' . ( '' !== $m[2] ? ' ' . $m[2] : '' );
		}

		return $value;
	}

	/**
	 * Renders docs/reference/functions.md.
	 *
	 * @return string
	 */
	private function render_functions() {
		$out  = $this->header( 'Functions', 'Every function the plugin declares, with signature, parameters, return value, and source file, generated from docblocks.' );
		$out .= "# Functions\n\n";
		$out .= "Functions declared under `src/` are core-bound. Functions declared in `secrets-api.php` belong to the plugin's own bootstrap and are not proposed for core.\n\n";

		$public   = array();
		$internal = array();

		foreach ( $this->functions as $name => $item ) {
			if ( '_' === $name[0] ) {
				$internal[ $name ] = $item;
			} else {
				$public[ $name ] = $item;
			}
		}

		ksort( $public, SORT_STRING );
		ksort( $internal, SORT_STRING );

		foreach ( $public as $name => $item ) {
			$out .= '## `' . $name . "()`\n\n";
			$out .= $this->render_callable_body( $item, $item['signature'] );
			$out .= $this->source_line( $item['file'] ) . "\n";
		}

		if ( $internal ) {
			$out .= "## Internal functions\n\n";
			$out .= "Prefixed with an underscore by WordPress convention: private to the API, not part of the surface plugins should call, and subject to change.\n\n";

			foreach ( $internal as $name => $item ) {
				$out .= '### `' . $name . "()`\n\n";
				$out .= $this->render_callable_body( $item, $item['signature'] );
				$out .= $this->source_line( $item['file'] ) . "\n";
			}
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * Renders docs/reference/classes.md.
	 *
	 * @return string
	 */
	private function render_classes() {
		$out  = $this->header( 'Classes', 'Every class and interface the plugin declares, with public constants and methods, generated from docblocks.' );
		$out .= "# Classes and interfaces\n\n";
		$out .= "WP-CLI command classes are documented in [wp-cli.md](wp-cli.md) rather than here.\n\n";

		$classes = array_filter(
			$this->classes,
			function ( $record ) {
				return 0 !== strpos( $record['file'], 'cli/' );
			}
		);

		ksort( $classes, SORT_STRING );

		foreach ( $classes as $name => $class ) {
			$out .= '## `' . $name . "`\n\n";

			$heritage = '*' . $class['kind'] . '*';

			if ( '' !== $class['extends'] ) {
				$heritage .= ' · extends `' . $class['extends'] . '`';
			}

			if ( $class['implements'] ) {
				$heritage .= ' · implements `' . implode( '`, `', $class['implements'] ) . '`';
			}

			$out .= $heritage . "\n\n";

			$doc = $class['doc'];

			if ( '' !== $doc['summary'] ) {
				$out .= $this->prose( $doc['summary'] ) . "\n\n";
			}

			if ( '' !== $doc['description'] ) {
				$out .= $this->prose( $doc['description'] ) . "\n\n";
			}

			if ( $doc['since'] ) {
				$out .= '**Since:** ' . implode( ', ', $doc['since'] ) . "\n\n";
			}

			$out .= $this->source_line( $class['file'] ) . "\n";

			$constants = array_filter(
				$class['constants'],
				function ( $constant ) {
					return 'public' === $constant['visibility'];
				}
			);

			if ( $constants ) {
				ksort( $constants, SORT_STRING );

				$out .= "### Constants\n\n| Constant | Value | Description |\n|---|---|---|\n";

				foreach ( $constants as $constant ) {
					$out .= '| ' . $this->code_cell( $constant['name'] ) . ' | ' . $this->code_cell( $constant['value'] ) . ' | ' . $this->cell( $constant['doc']['summary'] ) . " |\n";
				}

				$out .= "\n";
			}

			$methods = array_filter(
				$class['methods'],
				function ( $method ) {
					return 'public' === $method['visibility'];
				}
			);

			if ( $methods ) {
				ksort( $methods, SORT_STRING );

				$out .= "### Methods\n\n";

				foreach ( $methods as $method ) {
					$out .= '#### `' . $name . '::' . $method['name'] . "()`\n\n";
					$out .= $this->render_callable_body( $method, $method['signature'] );
				}
			}
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * Renders docs/reference/hooks.md.
	 *
	 * @return string
	 */
	private function render_hooks() {
		$out  = $this->header( 'Hooks', 'Every action and filter the plugin fires, with parameters and every call site, generated from docblocks.' );
		$out .= "# Hooks\n\n";

		if ( ! $this->hooks ) {
			$out .= "The source fires no actions and applies no filters.\n";

			return $out;
		}

		$out .= "There is no filter anywhere in core-bound code, and nothing on the retrieval path fires a hook. What follows is the complete list.\n\n";

		$hooks = $this->hooks;
		ksort( $hooks, SORT_STRING );

		foreach ( $hooks as $name => $hook ) {
			$out .= '## `' . $name . "`\n\n";
			$out .= '**Type:** ' . ucfirst( $hook['type'] ) . "\n\n";

			$doc = $hook['doc'];

			if ( null === $doc ) {
				$out .= "*No docblock found at any call site.*\n\n";
			} else {
				if ( '' !== $doc['summary'] ) {
					$out .= $this->prose( $doc['summary'] ) . "\n\n";
				}

				if ( '' !== $doc['description'] ) {
					$out .= $this->prose( $doc['description'] ) . "\n\n";
				}

				if ( $doc['params'] ) {
					$out .= "| Parameter | Type | Description |\n|---|---|---|\n";

					foreach ( $doc['params'] as $param ) {
						$out .= '| ' . $this->code_cell( $param['name'] ) . ' | ' . $this->code_cell( $param['type'] ) . ' | ' . $this->cell( $param['description'] ) . " |\n";
					}

					$out .= "\n";
				}

				if ( $doc['since'] ) {
					$out .= '**Since:** ' . implode( ', ', $doc['since'] ) . "\n\n";
				}
			}

			$files = array();

			foreach ( $hook['sites'] as $site ) {
				$files[ $site['file'] ] = isset( $files[ $site['file'] ] ) ? $files[ $site['file'] ] + 1 : 1;
			}

			ksort( $files, SORT_STRING );

			$out .= "**Fired from:**\n\n";

			foreach ( $files as $file => $times ) {
				$out .= '- [`' . $file . '`](../../' . $file . ')' . ( $times > 1 ? " ({$times} call sites)" : '' ) . "\n";
			}

			$out .= "\n";
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * Renders docs/reference/wp-cli.md.
	 *
	 * @return string
	 */
	private function render_cli() {
		$out  = $this->header( 'WP-CLI commands', 'Every wp secret and wp network-secret subcommand with its synopsis and options, generated from the command docblocks.' );
		$out .= "# WP-CLI commands\n\n";

		if ( ! $this->cli_commands ) {
			$out .= "No WP-CLI commands are registered.\n";

			return $out;
		}

		$out .= "Registered only when WP-CLI is running. Nothing under `cli/` is proposed for core.\n\n";

		$commands = $this->cli_commands;
		ksort( $commands, SORT_STRING );

		foreach ( $commands as $command => $class_name ) {
			$out .= '## `wp ' . $command . "`\n\n";

			if ( ! isset( $this->classes[ $class_name ] ) ) {
				$out .= '*Registered to `' . $class_name . "`, which was not found in the scanned source.*\n\n";

				continue;
			}

			$class = $this->classes[ $class_name ];
			$doc   = $class['doc'];

			if ( '' !== $doc['summary'] ) {
				$out .= $this->prose( $doc['summary'] ) . "\n\n";
			}

			if ( '' !== $doc['description'] ) {
				$out .= $this->prose( $doc['description'] ) . "\n\n";
			}

			$out .= '**Class:** `' . $class_name . '`';

			if ( '' !== $class['extends'] ) {
				$out .= ' extends `' . $class['extends'] . '`';
			}

			$out .= "\n\n" . $this->source_line( $class['file'] ) . "\n";

			$methods = $this->cli_methods( $class_name );

			foreach ( $methods as $subcommand => $method ) {
				$out .= $this->render_subcommand( $command, $subcommand, $method );
			}
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * Collects the public methods of a command class and its ancestors, keyed by subcommand name.
	 *
	 * @param string $class_name Command class.
	 *
	 * @return array<string, array>
	 */
	private function cli_methods( $class_name ) {
		$chain = array();
		$name  = $class_name;

		while ( '' !== $name && isset( $this->classes[ $name ] ) && ! in_array( $name, $chain, true ) ) {
			$chain[] = $name;
			$name    = $this->classes[ $name ]['extends'];
		}

		$methods = array();

		// Walk from the root ancestor down so an override in a child replaces the parent's method.
		foreach ( array_reverse( $chain ) as $ancestor ) {
			foreach ( $this->classes[ $ancestor ]['methods'] as $method ) {
				if ( 'public' !== $method['visibility'] || 0 === strpos( $method['name'], '__' ) ) {
					continue;
				}

				if ( isset( $method['doc']['tags']['ignore'] ) ) {
					continue;
				}

				$subcommand = isset( $method['doc']['tags']['subcommand'][0] )
					? $method['doc']['tags']['subcommand'][0]
					: str_replace( '_', '-', $method['name'] );

				$methods[ $subcommand ] = $method;
			}
		}

		ksort( $methods, SORT_STRING );

		return $methods;
	}

	/**
	 * Renders one subcommand: synopsis, description, options, examples.
	 *
	 * @param string $command    Top-level command name, e.g. 'secret'.
	 * @param string $subcommand Subcommand name, e.g. 'get'.
	 * @param array  $method     Method record.
	 *
	 * @return string
	 */
	private function render_subcommand( $command, $subcommand, array $method ) {
		$doc     = $method['doc'];
		$options = isset( $doc['sections']['OPTIONS'] ) ? $this->parse_cli_options( $doc['sections']['OPTIONS'] ) : array();

		$synopsis = 'wp ' . $command . ' ' . $subcommand;

		foreach ( $options as $option ) {
			$synopsis .= ' ' . $option['token'];
		}

		$out = '### `wp ' . $command . ' ' . $subcommand . "`\n\n";

		if ( '' !== $doc['summary'] ) {
			$out .= $this->prose( $doc['summary'] ) . "\n\n";
		}

		if ( '' !== $doc['description'] ) {
			$out .= $this->prose( $doc['description'] ) . "\n\n";
		}

		$out .= "```\n" . $synopsis . "\n```\n\n";

		if ( $options ) {
			$out .= "| Option | Description |\n|---|---|\n";

			foreach ( $options as $option ) {
				$description = $option['description'];

				if ( '' !== $option['default'] ) {
					$description .= ' Default: `' . $option['default'] . '`.';
				}

				if ( $option['choices'] ) {
					$description .= ' Options: `' . implode( '`, `', $option['choices'] ) . '`.';
				}

				$out .= '| ' . $this->code_cell( $option['token'] ) . ' | ' . $this->cell( $description ) . " |\n";
			}

			$out .= "\n";
		}

		if ( isset( $doc['sections']['EXAMPLES'] ) ) {
			$examples = $this->trim_blank_lines( $doc['sections']['EXAMPLES'] );

			if ( $examples ) {
				$out .= "**Examples**\n\n```\n" . implode( "\n", $examples ) . "\n```\n\n";
			}
		}

		if ( isset( $doc['tags']['when'][0] ) ) {
			$out .= '**Runs:** `' . $doc['tags']['when'][0] . "`\n\n";
		}

		$out .= $this->source_line( $method['file'] ) . "\n";

		return $out;
	}

	/**
	 * Parses a WP-CLI `## OPTIONS` section into ordered option records.
	 *
	 * Each option is a synopsis token (`<name>`, `[--flag=<value>]`, ...) followed by
	 * a `: description` line, optional continuation lines, and an optional `---`
	 * YAML block carrying `default:` and `options:`.
	 *
	 * @param string[] $lines The section's lines.
	 *
	 * @return array<int, array>
	 */
	private function parse_cli_options( array $lines ) {
		$options = array();
		$current = null;
		$in_yaml = false;
		$in_list = false;

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( '---' === $trimmed ) {
				$in_yaml = ! $in_yaml;
				$in_list = false;

				continue;
			}

			if ( $in_yaml && null !== $current ) {
				if ( preg_match( '/^default:\s*(.*)$/', $trimmed, $m ) ) {
					$options[ $current ]['default'] = trim( $m[1] );
					$in_list                        = false;
				} elseif ( preg_match( '/^options:\s*$/', $trimmed ) ) {
					$in_list = true;
				} elseif ( $in_list && preg_match( '/^-\s*(.+)$/', $trimmed, $m ) ) {
					$options[ $current ]['choices'][] = trim( $m[1] );
				}

				continue;
			}

			if ( '' === $trimmed ) {
				continue;
			}

			if ( preg_match( '/^(<[^>]+>|\[<[^>]+>\]|\[?--[^\s\]]+\]?)(\.\.\.)?$/', $trimmed ) ) {
				$options[] = array(
					'token'       => $trimmed,
					'description' => '',
					'default'     => '',
					'choices'     => array(),
				);
				$current   = count( $options ) - 1;

				continue;
			}

			if ( null === $current ) {
				continue;
			}

			$text = preg_replace( '/^:\s*/', '', $trimmed );

			$options[ $current ]['description'] = trim( $options[ $current ]['description'] . ' ' . $text );
		}

		return $options;
	}
}

exit( Secrets_API_Reference_Generator::main( $argv ) );
