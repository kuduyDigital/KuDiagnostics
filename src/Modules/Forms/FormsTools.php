<?php
/**
 * Tools: kuduy-forms-status, kuduy-forms-list, kuduy-forms-get.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Forms of every supported forms plugin through one tool set (D15 A). The executor's capability is only the floor;
 * each adapter applies its plugin's own permission per form.
 */
final class FormsTools implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode status | list | get.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-forms-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'status' => __( 'Forms plugins status', 'kuduy-mcp' ),
			'list'   => __( 'List forms', 'kuduy-mcp' ),
			default  => __( 'Read a form', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'status' => 'Forms plugins found on the site (Contact Form 7, Fluent Forms, WPForms), their versions, where entries are stored (Contact Form 7 keeps none itself, Flamingo stores them; WPForms Lite keeps none on the site) and whether the plugin\'s own abilities are on.',
			'list'   => 'Lists the forms your account may read in each forms plugin: plugin, id, title, number of stored entries (null when you may not read entries or the site stores none) and shortcode. Filter with plugin.',
			default  => 'Returns one form: fields (name, label, type, required, choices), messages and confirmations, email notifications (where submissions are emailed: recipient, sender, reply-to, Cc/Bcc) and settings. Integration keys (reCAPTCHA, payment, email services) are never returned.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		[ $properties, $required ] = match ( $this->mode ) {
			'status' => array( array(), array() ),
			'list'   => array( array( 'plugin' => Forms::plugin_input() ), array() ),
			default  => array(
				array(
					'plugin'  => Forms::plugin_input(),
					'form_id' => Forms::id_input(),
				),
				array( 'plugin', 'form_id' ),
			),
		};
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		return match ( $this->mode ) {
			'status' => array(
				'type'       => 'object',
				'properties' => array( 'plugins' => $list ),
				'required'   => array( 'plugins' ),
			),
			'list'   => array(
				'type'       => 'object',
				'properties' => array( 'forms' => $list ),
				'required'   => array( 'forms' ),
			),
			default  => array(
				'type'       => 'object',
				'properties' => array(
					'plugin'        => array( 'type' => 'string' ),
					'id'            => array( 'type' => 'integer' ),
					'title'         => array( 'type' => 'string' ),
					'entries'       => array( 'type' => array( 'integer', 'null' ) ),
					'shortcode'     => array( 'type' => 'string' ),
					'fields'        => $list,
					'messages'      => array( 'type' => 'object' ),
					'notifications' => $list,
				),
				'required'   => array( 'plugin', 'id', 'title', 'fields', 'notifications' ),
			),
		};//end match
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return Content::read_only();
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'status' === $this->mode ? 'edit_posts' : 'read';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Forms::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the plugin or form is missing or not readable.
	 */
	public function execute( array $input ): array {
		if ( 'status' === $this->mode ) {
			$plugins = array();
			foreach ( Forms::active() as $adapter ) {
				$plugins[] = array(
					'plugin'        => $adapter->slug(),
					'name'          => $adapter->name(),
					'version'       => $adapter->version(),
					'entries_store' => $adapter->entries_store(),
					'abilities'     => $adapter->abilities(),
				);
			}
			return array( 'plugins' => $plugins );
		}
		if ( 'list' === $this->mode ) {
			$adapters = isset( $input['plugin'] ) ? array( Forms::adapter( (string) $input['plugin'] ) ) : Forms::active();
			$forms    = array();
			foreach ( $adapters as $adapter ) {
				array_push( $forms, ...$adapter->forms() );
			}
			return array( 'forms' => $forms );
		}
		$adapter = Forms::adapter( (string) $input['plugin'] );
		AuditLog::target( $adapter->slug() . '_form', (string) (int) $input['form_id'] );
		return $adapter->form( (int) $input['form_id'] );
	}
}
