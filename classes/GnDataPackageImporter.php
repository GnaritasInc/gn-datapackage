<?php

class GnDataPackageImporter {

	function __construct () {
		$this->homeDir = dirname(dirname(__FILE__));
		$this->includeDir = $this->homeDir."/includes";

		$this->dataAction = "gndp_do_import";

		add_action("admin_menu", array(&$this, "adminMenu"));

	}

	function adminMenu () {
		add_options_page("Data Package Import", "Data Package Importer", "manage_options", "gn-datapackage", array(&$this, "controller"));
	}

	function controller () {
		include($this->includeDir . "/admin.php");
	}



}
