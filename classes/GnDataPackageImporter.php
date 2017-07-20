<?php

use frictionlessdata\datapackage;

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
		$fileInfo = $this->getUploadedFileInfo("zip_file");
		$filePath = $fileInfo['file'];
		$uploadDir = dirname($filePath);
		$dataPath = $uploadDir . "/zipdata/";
		$zip = $this->openZipFile($filePath);
		$this->extractZip($zip, $dataPath);

		$datapackage = $this->getDataPackage($dataPath . "datapackage.json");
	}

	function getDataPackage ($descriptor) {
		if (!file_exists($descriptor)) {
			throw new Exception("Package descriptor not found. Make sure your package has a datapackage.json file at the archive root.");			
		}

		return datapackage\Factory::datapackage($descriptor);
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
