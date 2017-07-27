<?php

use frictionlessdata\datapackage;
use frictionlessdata\tableschema\DataSources\CsvDataSource;

class GnDataPackageImporter {

	var $awsEndpoint = "https://6goo1zkzoi.execute-api.us-east-1.amazonaws.com/prod/datapackage2sql";
	var $ch = null;
	var $dataPath = "";
	var $createdTables = array();

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
		global $wpdb;
		$tablePrefix = trim($_POST['table_prefix']);
		$this->validateTablePrefix($tablePrefix);

		$fileInfo = $this->getUploadedFileInfo("zip_file");
		$filePath = $fileInfo['file'];
		$uploadDir = dirname($filePath);
		$dataPath = $uploadDir . "/zipdata/";
		$zip = $this->openZipFile($filePath);
		$this->extractZip($zip, $dataPath);

		$datapackage = $this->getDataPackage($dataPath . "datapackage.json", $dataPath);
		$this->dataPath = $dataPath;

		try {
			error_log("Starting db transaction");
			$wpdb->query("start transaction");
			$this->createSchema($datapackage, $tablePrefix);
			$this->populateData($datapackage, $tablePrefix);
			error_log("Committing");
			$wpdb->query("commit");
		}
		catch (Exception $e) {
			error_log("Rolling back");
			$wpdb->query("rollback");
			$this->rollBackTables();
			throw $e;
		}
	}

	function rollBackTables () {
		global $wpdb;
		$this->setForeignKeyChecks(0);
		foreach($this->createdTables as $table) {
			$wpdb->query("drop table if exists $table");
		}
		$this->setForeignKeyChecks(1);
	}

	function setForeignKeyChecks ($val=1) {
		global $wpdb;
		$wpdb->query($wpdb->prepare("SET FOREIGN_KEY_CHECKS=%d", $val));
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
		error_log("Creating schema tables");
		global $wpdb;				
		
		try {
			$this->setForeignKeyChecks(0);

			foreach($datapackage->resources() as $resource) {
				$descriptor = array("name"=>"temp", "resources"=>array($resource->descriptor()));
				$createSql = $this->getTableDefs($descriptor, $tablePrefix);
				$this->safeDbQuery($createSql);
				$this->createdTables[] = $tablePrefix.$resource->descriptor()->name;
			}			
			
			$this->setForeignKeyChecks(1);

			error_log("Done creating tables");
		}
		catch (Exception $e) {			
			$this->setForeignKeyChecks(1);
			throw new Exception("Error creating schema: ".$e->getMessage());		
		}		
	}

	function getCurlHandle () {
		if (is_null($this->ch)) {
			$this->ch = curl_init($this->awsEndpoint);
		}

		return $this->ch;
	}

	function getTableDefs ($descriptor, $tablePrefix) {
		$postData = compact('descriptor', 'tablePrefix');		
		
		$ch = $this->getCurlHandle();                                                                 
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
		error_log("Populating data");
		global $wpdb;
		foreach ($datapackage->resources() as $name => $resource) {
			$tableName = $tablePrefix . $name;
			$csvFileName = $resource->descriptor()->path;
			$csv = new CsvDataSource($this->dataPath .  $csvFileName);
			$csv->open();
			for ($lineNum = 1; !$csv->isEof(); $lineNum++) {
				$row = $csv->getNextLine();				
				try {
					$this->insertDataRow($tableName, $row);
				}
				catch (Exception $e) {
					throw new Exception("Error inserting data from {$csvFileName}:{$lineNum}: ".$e->getMessage());		
				}
			}
			$csv->close();
		}
	}

	function insertDataRow ($tableName, $row) {
		global $wpdb;
		$valueArray = array();
		$valueFormat = array();
		foreach ($row as $value) {
			if (strlen(trim($value))) {
				$valueFormat[] = "%s";
				$valueArray[] = $value;
			}
			else {
				$valueFormat[] = "null";
			}
		}
		$sql = "insert into `$tableName` (`". implode('`, `', array_keys($row)) ."`)";
		$sql .= " values (". implode(', ', $valueFormat) .")";

		$this->safeDbQuery($wpdb->prepare($sql, $valueArray));
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
