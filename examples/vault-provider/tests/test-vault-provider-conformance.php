<?php
/**
 * Runs the shared provider conformance suite against a real Vault dev server.
 *
 * @package SecretsAPI\Examples
 */

class Tests_Vault_Provider_Conformance extends WP_Secrets_Provider_Conformance {

	/** @var Vault_Test_Server */
	private $server;

	public function set_up() {
		parent::set_up();

		$this->server = new Vault_Test_Server();
		$this->server->wipe();
	}

	protected function provider() {
		return $this->server->provider();
	}
}
