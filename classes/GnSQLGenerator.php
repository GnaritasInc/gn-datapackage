<?php

class GnSQLGenerator {

	public static $MAX_KEY_LEN = 255;

	var $tablePrefix = "";
	
	function __construct ($tablePrefix="") {
		$this->tablePrefix = $tablePrefix;
	}

	function getTableSQL ($resource) {
		
		$output = "";
		$output .= "create table " . $this->getTableRef($resource->descriptor()->name) . " (";
		$output .= "\n\t" . implode($this->getColumnDefs($resource->descriptor()->schema), ",\n\t");
		$output .= "\n);\n";

		return $output;
	}

	function getTableRef ($name) {
		return $this->quoteNames($this->tablePrefix . $name); 
	}

	function getFieldConstraints ($field) {
		$constraints = array();
		if ($field->constraints) {
			return "";
		}
		if ($field->constraints->required) {
			$constraints[] = "not null";
		}
		if ($field->constraints->unique) {
			$constraints[] = "unique";
		}

		return implode(" ", $constraints);
	}

	function sqlDataType ($field, $schema) {
		$types = array(
			"string" =>"text",
			"number" =>"decimal",
			"integer" =>"int",
			"boolean" =>"varchar(5)",
			"date" =>"date",
			"time" =>"time",
			"datetime" =>"datetime"
		);

		if ($this->isEnum($field)) {
			return $this->getEnumDef($field);
		}

		if ($field->type == "string" && $this->isKeyColumn($field, $schema)) {
			return sprintf("varchar(%d)", self::$MAX_KEY_LEN);
		}
		
		if($field->type == "string" && $field->constraints && in_array("maxLength", $field->constraints)) {		
			return sprintf("varchar(%d)", $field->constraints->maxLength);
		}

		return array_key_exists($field->type, $types) ? $types[$field->type] : "text";
	}
	

	function isKeyColumn ($field, $schema) {
		return $this->isPrimaryKey($field, $schema) || $this->isForeignKey($field, $schema) || $this->isUniqueKey($field);
	}

	function isUniqueKey ($field) {
		return $field->constraints && $field->constraints->unique;
	}

	function isPrimaryKey ($field, $schema) {
		if (!$schema->primaryKey) return false;
		$fieldName = $field->name;
		$primaryKeys = $this->getArray($schema->primaryKey);
		return in_array($fieldName, $primaryKeys);
	}

	function isForeignKey ($field, $schema) {
		if (!$schema->foreignKeys) return false;
		$fieldName = $field->name;
		$fkCols = array();
		foreach($schema->foreignKeys as $fk) {
			$fkCols =  array_merge($fkCols, $this->getArray($fk->fields));
		};

		return in_array($fieldName, $fkCols);
	}

	function isEnum ($field) {
		return ($field->constraints && $field->constraints->enum && count($field->constraints->enum)) ? true : false;
	}

	function getEnumDef ($field) {	
		return " enum(". $this->quoteArray($field->constraints->enum) .")";	
	}

	function quoteArray ($arr) {
		global $wpdb;
		$format = $this->getFormatString('%s', count($arr));

		return $wpdb->prepare($format, $arr);
	}


	function getColumnDefs  ($schema) {
		$defs = array();
		foreach($schema->fields as $field) {
			$this->validateIdentifier($field->name);
			$def = sprintf("`%s` ", $field->name);
			$def .= $this->sqlDataType($field, $schema);
			$constraints = $this->getFieldConstraints($field);
			if ($constraints) {
				$def .= " " . $constraints;
			}
			$defs[] = $def;
		}

		if ($schema->primaryKey) {
			$cols = $this->getArray($schema->primaryKey);		
			$defs[] = "primary key (". $this->quoteNames($cols) .")";
		}

		if ($schema->foreignKeys) {
			foreach($schema->foreignKeys as $fk) {
				$fkCols = $this->getArray($fk->fields);
				$def = "foreign key(". $this->quoteNames($fkCols) .")";			
				$def .= " references " . $this->getTableRef($fk->reference->resource);
				$def .= " (" . $this->quoteNames($this->getArray($fk->reference->fields)) . ")";
				$defs[] = $def;
			};
		}

		return $defs;
	}

	function getArray ($val) {
		return is_array($val) ? $val : array($val);
	}

	function quoteNames ($cols) {
		$cols = $this->getArray($cols);
		$this->validateColNames($cols);
		$format = $this->getFormatString('`%s`', count($cols));

		return vsprintf($format, $cols);
	}

	function getFormatString ($format, $length, $delimiter=", ") {
		return implode($delimiter, array_fill(0, $length, $format));
	}

	function validateColNames ($cols) {
		foreach ($cols as $col) {
			$this->validateIdentifier($col);
		}
	}

	function validateIdentifier ($col) {
		if (preg_match('/[^0-9A-Za-z_-]/', $col)) {
			throw new Exception("Invalid identifier: '$col'. Use only letters, numbers, underscores or hyphens in table or column names.");
		}
	}

}
