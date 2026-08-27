<?php
/**
 * WP-CLI: wp network-secret
 *
 * Never copied to core.
 *
 * @package SecretsAPI
 */

/**
 * Manages network-scope secrets. Every subcommand is inherited from
 * WP_CLI_Secret_Command; this class exists only to flip the scope and to refuse
 * outright on a single-site install, which the parent constructor enforces.
 */
class WP_CLI_Secret_Network_Command extends WP_CLI_Secret_Command {

	/**
	 * Always network scope for this subclass.
	 *
	 * @var bool
	 */
	protected $network = true;
}
