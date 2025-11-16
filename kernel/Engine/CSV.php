<?php
namespace Manomite\Engine;
use \League\Csv\Reader;
use \League\Csv\Writer;
use \League\Csv\Statement;

class CSV {

	private $filename;
	public function __construct($filename){
		$this->filename = $filename;
	}

	public function writeHead(array $head){
        try {
            $csv = Reader::createFromPath($this->filename, 'r');
			$offset = $csv->setHeaderOffset(0);
            $header = $csv->getHeader();
            if (empty($header)) {
                $writer = Writer::createFromPath($this->filename, 'w');
                $writer->insertOne($head);
            }
			return true;
        } catch(\Exception $e){
            if (empty($header)) {
                $writer = Writer::createFromPath($this->filename, 'w');
                $writer->insertOne($head);
            }
		}
	}

	public function writeCSV(array $records, string $search, string $column){
		try {
			$duplicate = false;
			$reader = Reader::createFromPath($this->filename, 'r');
			$reader->setHeaderOffset(0);
			$data = Statement::create()->process($reader);
			foreach ($data->fetchColumn($column) as $value) {
				if (trim($search) === trim($value)) {
					$duplicate = true;
					break;
				} 
			}
            if ($duplicate === false) {
                $writer = Writer::createFromPath($this->filename, 'a');
                $writer->insertOne($records);
				return true;
            }
			return true;
		} catch(\Exception $e){
			return $e->getMessage();
		}
	}

	public function customWrite(array $records, string $search, string $column){
		$csv = array_map('str_getcsv', file($this->filename));
		$duplicate = false;
		$linee = '';
        foreach ($csv as $line) {
			$linee = (isset($line[$column]) ? $line[$column] : '');
			if (trim($search) == trim($linee)) {
				$duplicate = true;
				break;
            } 
        }
		if($duplicate === false){
			$fp = fopen($this->filename, 'a');
			fputcsv($fp, $records);
			fclose($fp);
			return true;
		}
		return false;
	}

	public function write(array $records){
		$writer = Writer::createFromPath($this->filename, 'a');
		$writer->insertOne($records);
		return true;
	}

	function convert_csv_to_json($csv_data){
		// convert csv data to an array
		$data = array_map("str_getcsv", explode("\n", $csv_data));
		// use the first row as column headers
		$columns = $data[0];
		// create array to hold our converted data
		$json = [];
		// iterate through each row in the data
		foreach ($data as $row_index => $row_data) {
	
			// skip the first row, since it's the headers
			if($row_index === 0) continue;
	
			// make sure we establish each new row as an array
			$json[$row_index] = [];
	
			// iterate through each column in the row
			foreach ($row_data as $column_index => $column_value) {
	
				// get the key for each entry
				$label = $columns[$column_index];
	
				// add this column's value to this row's index / column's key
				$json[$row_index][$label] = $column_value;       
			}
		}
	
		// bam
		return $json;
	}
}
