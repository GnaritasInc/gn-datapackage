<?php

use frictionlessdata\datapackage;

class GnDataPackageImporter {

	var $awsEndpoint = "https://6goo1zkzoi.execute-api.us-east-1.amazonaws.com/prod/datapackage2sql";

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
		if ($_POST['gndp_data_action'] == $this->dataAction && check_admin_referer($this->dataAction, 'gndp_nonce')) {
			try {
				
				$this->doImport();

				if (!$this->warning) {
					$this->msg = "Data imported successfully.";
				}

			}
			catch (Exception $e) {
				$this->error = $e->getMessage();
			}
		}

		include($this->includeDir . "/admin.php");
	}

	function doImport () {
		$tablePrefix = trim($_POST['table_prefix']);
		$this->validateTablePrefix($tablePrefix);

		$fileInfo = $this->getUploadedFileInfo("zip_file");
		$filePath = $fileInfo['file'];
		$uploadDir = dirname($filePath);
		$dataPath = $uploadDir . "/zipdata/";
		$zip = $this->openZipFile($filePath);
		$this->extractZip($zip, $dataPath);

		$datapackage = $this->getDataPackage($dataPath . "datapackage.json", $dataPath);


		$this->createSchema($datapackage, $tablePrefix);
		$this->populateData($datapackage, $tablePrefix);
	}

	function validateTablePrefix ($prefix) {
		if (!preg_match('/^[a-z0-9_]*$/i', $prefix)) {
			throw new Exception("Table prefx must contain only letters, numbers or underscores.");		
		}
	}

	function safeDbQuery ($sql) {
		global $wpdb;
		$wpdb->query($sql);
		if ($err = $wpdb->last_error) {
			throw new Exception($err);
		}
	}

	function createSchema (&$datapackage, $tablePrefix) {
		global $wpdb;		
		
		$wpdb->query("SET FOREIGN_KEY_CHECKS=0");
		$wpdb->query("start transaction");
		
		try {
			
			foreach($datapackage->resources() as $resource) {
				$descriptor = array("name"=>"temp", "resources"=>array($resource->descriptor()));
				$createSql = $this->getTableDefs($descriptor, $tablePrefix);
				$this->safeDbQuery($createSql);
			}
			
			$wpdb->query("commit");
			$wpdb->query("SET FOREIGN_KEY_CHECKS=1");
		}
		catch (Exception $e) {
			$wpdb->query("rollback");
			$wpdb->query("SET FOREIGN_KEY_CHECKS=1");
			throw new Exception("Error creating schema: ".$e->getMessage());		
		}		
	}

	function getTableDefs ($descriptor, $tablePrefix) {
		$postData = compact('descriptor', 'tablePrefix');		
		
		$ch = curl_init($this->awsEndpoint);                                                                 
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$responseBody = curl_exec($ch);
		$responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($responseStatus != 200) {
			throw new Exception("Error getting schema SQL: $responseBody");	
		}

		return $responseBody;
	}

	function populateData (&$datapackage, $tablePrefix) {

	}

	function getDataPackage ($descriptor, $basePath) {
		if (!file_exists($descriptor)) {
			throw new Exception("Package descriptor not found. Make sure your package has a datapackage.json file at the archive root.");			
		}

		return datapackage\Factory::datapackage($descriptor, $basePath);
	}

	function getUploadedFileInfo ($key) {
		$fileInfo = wp_handle_upload($_FILES[$key], array("test_form"=>false, "action"=>"gndp-import"));
		if ($fileInfo['error']) {
			throw new Exception("File upload error: ".$fileInfo['error']);
		}

		return $fileInfo;
	}

	function openZipFile ($path) {
		$zip = new ZipArchive();
		$result = $zip->open($path);
		if ($result === true) {
			return $zip;
		}
		else {
			throw new Exception("Error reading Zip file: ".$this->getZipErrorMessage($result));			
		}
	}

	function extractZip ($zip, $destination) {
		if (!$zip->extractTo($destination)) {
			throw new Exception("Error extracting zip file.");	
		}
	}

	function getZipErrorMessage ($code) {
		$messages = array(
			ZipArchive::ER_EXISTS => "File already exists.",
			ZipArchive::ER_INCONS => "Zip archive inconsistent.",
			ZipArchive::ER_INVAL => "Invalid argument.",
			ZipArchive::ER_MEMORY => "Malloc failure.",
			ZipArchive::ER_NOENT => "No such file.",
			ZipArchive::ER_NOZIP => "Not a zip archive.",
			ZipArchive::ER_OPEN => "Can't open file.",
			ZipArchive::ER_READ => "Read error.",
			ZipArchive::ER_SEEK => "Seek error.",
		);

		return array_key_exists($code, $messages) ? $messages[$code] : "Unknown error.";
	}



}
