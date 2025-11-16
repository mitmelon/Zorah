<?php

namespace Manomite\Engine;

class Helper
{
    public function format($n, $precision = 2)
    {
        if ($n < 900) {
            $n_format = number_format($n, $precision);
            $suffix = '';
        } elseif ($n < 900000) {
            $n_format = number_format($n / 1000, $precision);
            $suffix = 'K';
        } elseif ($n < 900000000) {
            $n_format = number_format($n / 1000000, $precision);
            $suffix = 'M';
        } elseif ($n < 900000000000) {
            $n_format = number_format($n / 1000000000, $precision);
            $suffix = 'B';
        } else {
            $n_format = number_format($n / 1000000000000, $precision);
            $suffix = 'T';
        }
        if ($precision > 0) {
            $dotzero = '.' . str_repeat('0', $precision);
            $n_format = str_replace($dotzero, '', $n_format);
        }
        return $n_format . $suffix;
    }

    public function decimal_2($price)
    {
        return number_format(floor($price * 100) / 100, 2, '.', '');
    }

    public function trim($input, $length, $ellipses = true, $strip_html = false)
    {
        //strip tags, if desired
        if ($strip_html) {
            $input = strip_tags($input);
        }

        //no need to trim, already shorter than trim length
        if (strlen($input) <= $length) {
            return $input;
        }

        //find last space within length
        $last_space = strrpos(substr($input, 0, $length), ' ');
        $trimmed_text = substr($input, 0, $last_space);

        //add ellipses ( ... )
        if ($ellipses) {
            $trimmed_text .= '...';
        }

        return $trimmed_text;
    }

    public function getPercentChange($old, $new)
    {
        $decrease = abs($old - $new);
        if ($old === 0) {
            return $decrease;
        }
        return ($decrease / $old) * 100;
    }

    public function number_to_word($num = '')
    {
        $num    = ( string ) (( int ) $num);

        if (( int ) ($num) && ctype_digit($num)) {
            $words  = array( );

            $num    = str_replace(array( ',' , ' ' ), '', trim($num));

            $list1  = array('','one','two','three','four','five','six','seven',
            'eight','nine','ten','eleven','twelve','thirteen','fourteen',
            'fifteen','sixteen','seventeen','eighteen','nineteen');

            $list2  = array('','ten','twenty','thirty','forty','fifty','sixty',
            'seventy','eighty','ninety','hundred');

            $list3  = array('','thousand','million','billion','trillion',
            'quadrillion','quintillion','sextillion','septillion',
            'octillion','nonillion','decillion','undecillion',
            'duodecillion','tredecillion','quattuordecillion',
            'quindecillion','sexdecillion','septendecillion',
            'octodecillion','novemdecillion','vigintillion');

            $num_length = strlen($num);
            $levels = ( int ) (($num_length + 2) / 3);
            $max_length = $levels * 3;
            $num    = substr('00'.$num, -$max_length);
            $num_levels = str_split($num, 3);

            foreach ($num_levels as $num_part) {
                $levels--;
                $hundreds   = ( int ) ($num_part / 100);
                $hundreds   = ($hundreds ? ' ' . $list1[$hundreds] . ' Hundred' . ($hundreds == 1 ? '' : 's') . ' ' : '');
                $tens       = ( int ) ($num_part % 100);
                $singles    = '';

                if ($tens < 20) {
                    $tens = ($tens ? ' ' . $list1[$tens] . ' ' : '');
                } else {
                    $tens = ( int ) ($tens / 10);
                    $tens = ' ' . $list2[$tens] . ' ';
                    $singles = ( int ) ($num_part % 10);
                    $singles = ' ' . $list1[$singles] . ' ';
                }
                $words[] = $hundreds . $tens . $singles . (($levels && ( int ) ($num_part)) ? ' ' . $list3[$levels] . ' ' : '');
            }
            $commas = count($words);
            if ($commas > 1) {
                $commas = $commas - 1;
            }

            $words  = implode(', ', $words);

            //Some Finishing Touch
            //Replacing multiples of spaces with one space
            $words  = trim(str_replace(' ,', ',', trim_all(ucwords($words))), ', ');
            if ($commas) {
                $words  = str_replace_last(',', ' and', $words);
            }

            return $words;
        } elseif (! (( int ) $num)) {
            return 'Zero';
        }
        return '';
    }

    public function convertMemorySize($strval, string $to_unit = 'b')
    {
        $strval    = strtolower(str_replace(' ', '', $strval));
        $val       = floatval($strval);
        $to_unit   = strtolower(trim($to_unit))[0];
        $from_unit = str_replace($val, '', $strval);
        $from_unit = empty($from_unit) ? 'b' : trim($from_unit)[0];
        $units     = 'kmgtph';  // (k)ilobyte, (m)egabyte, (g)igabyte and so on...


        // Convert to bytes
        if ($from_unit !== 'b') {
            $val *= 1024 ** (strpos($units, $from_unit) + 1);
        }


        // Convert to unit
        if ($to_unit !== 'b') {
            $val /= 1024 ** (strpos($units, $to_unit) + 1);
        }


        return $val;
    }

    public function convert_to_bytes($p_sFormatted)
    {
        $aUnits = array('B' => 0, 'KB' => 1, 'MB' => 2, 'GB' => 3, 'TB' => 4, 'PB' => 5, 'EB' => 6, 'ZB' => 7, 'YB' => 8);
        $sUnit = strtoupper(trim(substr($p_sFormatted, -2)));
        if (intval($sUnit) !== 0) {
            $sUnit = 'B';
        }
        if (!in_array($sUnit, array_keys($aUnits))) {
            return false;
        }
        $iUnits = trim(substr($p_sFormatted, 0, strlen($p_sFormatted) - 2));
        if (!intval($iUnits) == $iUnits) {
            return false;
        }
        return $iUnits * pow(1024, $aUnits[$sUnit]);
    }

    public function formatBytes($bytes, $precision = 2)
    {
        $units = array("b", "kb", "mb", "gb", "tb");

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . " " . $units[$pow];
    }

    public function formatData(array $data)
    {
        $data = json_encode($data, JSON_PRETTY_PRINT);
        return '<pre>'.htmlspecialchars($data).'</pre>';
    }

    public function mask($data, $showStart = 0, $showEnd = 0, $showMiddle = 0, $maskChar = '*', $minLength = 10, $maxLength = 20)
    {
        $length = strlen($data);
        $maskedLength = $length - $showStart - $showEnd;

        if ($maskedLength <= 0 || $showStart + $showEnd >= $length) {
            return $data; // Return original data if masking is not possible
        }

        $maskedPart = str_repeat($maskChar, $maskedLength);

        if ($showMiddle > 0 && $maskedLength > $showMiddle) {
            $middleStart = $showStart + floor(($maskedLength - $showMiddle) / 2);
            $middlePart = substr($data, $middleStart, $showMiddle);
            $maskedPart = substr_replace($maskedPart, $middlePart, floor(($maskedLength - $showMiddle) / 2), $showMiddle);
        }

        $result = '';
        if ($showStart > 0) {
            $result .= substr($data, 0, $showStart);
        }
        $result .= $maskedPart;
        if ($showEnd > 0) {
            $result .= substr($data, -$showEnd);
        }

        // Shorten the result if minLength and maxLength are specified
        if ($minLength > 0 && $maxLength >= $minLength) {
            $currentLength = strlen($result);
            if ($currentLength > $maxLength) {
                // If result is longer than maxLength, truncate it
                $middleLength = $maxLength - $showStart - $showEnd;
                if ($middleLength > 0) {
                    $result = substr($result, 0, $showStart) .
                              str_repeat($maskChar, $middleLength) .
                              ($showEnd > 0 ? substr($result, -$showEnd) : '');
                } else {
                    $result = substr($result, 0, $maxLength);
                }
            } elseif ($currentLength < $minLength) {
                // If result is shorter than minLength, pad it
                $result .= str_repeat($maskChar, $minLength - $currentLength);
            }
        }

        return $result;
    }

    /**
     * Extract entire div structures based on field names
     *
     * @param string $html The HTML content
     * @param array $fields Array of field names to extract
     * @return array Extracted div structures
     */

     public function extractFormFields($html, $fields)
     {
         $dom = new \DOMDocument();
         libxml_use_internal_errors(true);
         $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
         libxml_clear_errors();

         $divs = $dom->getElementsByTagName('div');
         $extractedFields = array();
        foreach ($divs as $div) {
            $class = $div->getAttribute('class');
            $class = explode(' ', $class);
            foreach ($fields as $field) {
                $fieldName = preg_replace('/^add/', '', $field);
                if(!empty($fieldName) && !empty($class[1])){
                    if(strtolower($fieldName) === strtolower($class[1])){
                        $extractedFields[$field] = $dom->saveHTML($div);
                    }
                }
            }
        }
          
         return $extractedFields;
     }
}
