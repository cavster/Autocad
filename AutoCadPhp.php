
<?php
 ini_set('max_execution_time',300);//to pervent time error//changed to account for huge processing rates of the new files
// Function that checks whether the data are the on-screen text.
// It works in the following way:
// an array arrfailAt stores the control words for the current state of the stack, which show that
// input data are something else than plain text.
// For example, there may be a description of font or color palette etc. 
function rtf_isPlainText($s) {
    $arrfailAt = array("*", "fonttbl", "colortbl", "datastore", "themedata");
    for ($i = 0; $i < count($arrfailAt); $i++)
        if (!empty($s[$arrfailAt[$i]]))
            return false;
    return true;
}
 
function rtf2text($filename) {
    //echo "<h1>" . $filename . "</h1>";
    // Read the data from the input file.
    $text = file_get_contents($filename);
    if (!strlen($text))
        return "";
    return rtfStr2text($text);
}
 
function rtfStr2text($text) {
    // Create empty stack array.
    $document = "";
    $stack = array();
    $j = -1;
    // Read the data character-by- character…
    for ($i = 0, $len = strlen($text); $i < $len; $i++) {
        $c = $text[$i];
 
        // Depending on current character select the further actions.
        switch ($c) {
            // the most important key word backslash
            case "\\":
                // read next character
                $nc = $text[$i + 1];
 
                // If it is another backslash or nonbreaking space or hyphen,
                // then the character is plain text and add it to the output stream.
                if ($nc == '\\' && rtf_isPlainText($stack[$j]))
                    $document .= '\\';
                elseif ($nc == '~' && rtf_isPlainText($stack[$j]))
                    $document .= ' ';
                elseif ($nc == '_' && rtf_isPlainText($stack[$j]))
                    $document .= '-';
                // If it is an asterisk mark, add it to the stack.
                elseif ($nc == '*')
                    $stack[$j]["*"] = true;
                // If it is a single quote, read next two characters that are the hexadecimal notation
                // of a character we should add to the output stream.
                elseif ($nc == "'") {
                    $hex = substr($text, $i + 2, 2);
                    if (rtf_isPlainText($stack[$j]))
                        $document .= html_entity_decode("&#" . hexdec($hex) . ";");
                    //Shift the pointer.
                    $i += 2;
                    // Since, we’ve found the alphabetic character, the next characters are control word
                    // and, possibly, some digit parameter.
                } elseif ($nc >= 'a' && $nc <= 'z' || $nc >= 'A' && $nc <= 'Z') {
                    $word = "";
                    $param = null;
 
                    // Start reading characters after the backslash.
                    for ($k = $i + 1, $m = 0; $k < strlen($text); $k++, $m++) {
                        $nc = $text[$k];
                        // If the current character is a letter and there were no digits before it,
                        // then we’re still reading the control word. If there were digits, we should stop
                        // since we reach the end of the control word.
                        if ($nc >= 'a' && $nc <= 'z' || $nc >= 'A' && $nc <= 'Z') {
                            if (empty($param))
                                $word .= $nc;
                            else
                                break;
                            // If it is a digit, store the parameter.
                        } elseif ($nc >= '0' && $nc <= '9')
                            $param .= $nc;
                        // Since minus sign may occur only before a digit parameter, check whether
                        // $param is empty. Otherwise, we reach the end of the control word.
                        elseif ($nc == '-') {
                            if (empty($param))
                                $param .= $nc;
                            else
                                break;
                        }
                        else
                            break;
                    }
                    // Shift the pointer on the number of read characters.
                    $i += $m - 1;
 
                    // Start analyzing what we’ve read. We are interested mostly in control words.
                    $toText = "";
 
                    switch (strtolower($word)) {
                        // If the control word is "u", then its parameter is the decimal notation of the
                        // Unicode character that should be added to the output stream.
                        // We need to check whether the stack contains \ucN control word. If it does,
                        // we should remove the N characters from the output stream.
                        case "u":
                            $toText .= html_entity_decode("&#x" . dechex($param) . ";");
                            $ucDelta = @$stack[$j]["uc"];
                            if ($ucDelta > 0)
                                $i += $ucDelta;
                            break;
                        // Select line feeds, spaces and tabs.
                        case "par": case "page": case "column": case "line": case "lbr":
                            $toText .= "\n";
                            break;
                        case "emspace": case "enspace": case "qmspace":
                            $toText .= " ";
                            break;
                        case "tab": $toText .= "\t";
                            break;
                        // Add current date and time instead of corresponding labels.
                        case "chdate": $toText .= date("m.d.Y");
                            break;
                        case "chdpl": $toText .= date("l, j F Y");
                            break;
                        case "chdpa": $toText .= date("D, j M Y");
                            break;
                        case "chtime": $toText .= date("H:i:s");
                            break;
                        // Replace some reserved characters to their html analogs.
                        case "emdash": $toText .= html_entity_decode("&mdash;");
                            break;
                        case "endash": $toText .= html_entity_decode("&ndash;");
                            break;
                        case "bullet": $toText .= html_entity_decode("&#149;");
                            break;
                        case "lquote": $toText .= html_entity_decode("&lsquo;");
                            break;
                        case "rquote": $toText .= html_entity_decode("&rsquo;");
                            break;
                        case "ldblquote": $toText .= html_entity_decode("&laquo;");
                            break;
                        case "rdblquote": $toText .= html_entity_decode("&raquo;");
                            break;
                        // Add all other to the control words stack. If a control word
                        // does not include parameters, set &param to true.
                        default:
                            $stack[$j][strtolower($word)] = empty($param) ? true : $param;
                            break;
                    }
                    // Add data to the output stream if required.
                    $stackTest = "cf1";
                    if (rtf_isPlainText($stack[$j])) {
                        $document .= $toText;
                    }
                }
 
                $i++;
                break;
            // If we read the opening brace {, then new subgroup starts and we add
            // new array stack element and write the data from previous stack element to it.
            case "{":
                array_push($stack, $stack[$j++]);
                break;
            // If we read the closing brace }, then we reach the end of subgroup and should remove 
            // the last stack element.
            case "}":
                array_pop($stack);
                $j--;
                break;
            // Skip “trash”.
            case '\0': case '\r': case '\f': case '\n': break;
            // Add other data to the output stream if required.
            default:
                if (rtf_isPlainText($stack[$j]))
                    $document .= $c;
                break;
        }
    }
    // Return result.
    return $document;
}
 
function msWord2Text($userDoc) {
    $iLineTeller = 0;
    $sPreviousLine = "";
 
    $line = file_get_contents($userDoc);
    $lines = explode(chr(0x0D), $line);
    $outtext = "";
 
    foreach ($lines as $thisline) {
        $pos = strpos($thisline, chr(0x00));
        $stringlengte = strlen($thisline);
        if (($pos !== FALSE) || ($stringlengte == 0)) {
            //print("$thisline\n"); 
        } else {
            //first line bug... 
            if ($iLineTeller == 0) {
                $lastpos = strrpos($sPreviousLine, chr(0x00));
                $sTekst = substr($sPreviousLine, $lastpos, strlen($sPreviousLine) - $lastpos);
                $outtext .= $sTekst . "\n";
            }
            $outtext .= $thisline . "\n";
            $iLineTeller++;
        }
        if ($stringlengte != 0)
            $sPreviousLine = $thisline;
    }
 
    $outtext = preg_replace("/[^a-zA-Z0-9\s\,\.\-\n\r\é\è\ç\ë\à\'\:\t@\/\_\(\)]/", "", $outtext);
 
    return $outtext;
}
 
function process_file_ajax($file) {
    /*
     * Begin set of variables to match.
     */

	
    /*
     * End set of variables to match.
     */
 
    $plainTextDocExtra = msWord2Text($file);
    $plainTextDoc = rtf2text($file);
//echo $plainTextDoc;
	echo "got here";//Tedds calcualtion version not in retaining walls $plain text doc???"?
	//AC1027
	
   
      
 echo "got here 3";// gets here in 
 function get_string_between($string, $start, $end){//Delect the coby of this at end//why wont you workl!!!!!
    $string = " ".$string;
    $ini = strpos($string,$start);
    if ($ini == 0) return " something wrong";
    $ini += strlen($start);
    $len = strpos($string,$end,$ini) - $ini;
    return substr($string,$ini,$len);
}
            
 //can be done directly with plain tect doc here????

 if(strpos($plainTextDoc, 'AcDbEntity') !== false){//may need to change this
 echo "Its A Autocad";

	echo "There is this many Xrefs files</br>";
	$counter=substr_count($plainTextDoc, '..dwg');
	
	
	echo $counter;//works for different files types maybe do different checks save as varables and add them

  echo "getting names";
            $pos1=strpos($plainTextDoc, "..dwg");
			$pos2=$pos1;
			
			$test1= substr($plainTextDoc, $pos2);//keep changeing this
			//try take one string away from another get last few charactors maybe
			$test2= str_replace($test1, "",$plainTextDoc);
			
		//try replacing may be problem with space
		$testback=strrev($test2);//reverse it scan to 3 and rereverse to get back will be problem if number 3 is in file 
		//echo $testback;
	$testReplace = str_replace(" ", ".", $testback);//try this
	//echo $testReplace;
	//echo $testback;
	//echo $testReplace;
	$newtest=strstr($testReplace, '3..', true);
	//echo $newtest;
	//now reverse it!
	//$myVar=get_string_between($testReplace,"@70 @@@@36 @10 0.0 @20 0.0 @30 0.0 @@3","@@1 ..dwg");
	//echo "this is test var <b>";
	//echo $myVar;

	$NewString=strrev($newtest);
	//echo $NewString;//do it tomoro
	
		if($pos2==$pos1)
		{
		echo "this is the problem";
		}
	//	echo $test1;
			echo"Here we go <br>";
		//Works wooho
			 $FinalString = substr($NewString, 0, -5);//needs to be -5 for some reason???
			echo $FinalString;
			for($x=0;$x<$counter;$x++){
			 $pos2=strpos($plainTextDoc, "..dwg",$pos1+strlen("..dwg"));
			 $test1= substr($plainTextDoc, $pos2);
			 $test2= str_replace($test1, "",$plainTextDoc);//subtract it
			 $testback=strrev($test2);
			 $testReplace = str_replace(" ", ".", $testback);//replace with . to account for number 3 and space bug bug
			 $newtest=strstr($testReplace, '3..', true);
			 $NewString=strrev($newtest);
			 echo"list of xref files";
			
			 $FinalString = substr($NewString, 0, -5);
			 echo $FinalString;
			 $pos1=$pos2;
		//	echo $myVar;//change big striung varable use pos to cresdit new one
			}
			echo"<br> this one here";
			//echo $plainTextDoc;
  
 }
$counter=substr_count($plainTextDoc, 'AcDbMText');
	
	//echo $plainTextDoc;
	echo $counter;
 echo "getting Mtext <br>";
            $pos1=strpos($plainTextDoc, "ACAD_MTEXT");
		
			
			$test1= substr($plainTextDoc, $pos1);//keep changeing this
			//try take one string away from another get last few charactors maybe
  //delected the array here
  // echo $test1;
//$Tester= get_string_between($test1,"AcDbMText","ACAD");
//echo $Tester;
// try shaveing off 
//echo $plainTextDoc;

$test2= str_replace($test1, "",$plainTextDoc);//take away
 //73 1 44 1.0 1001
 ///echo $test2;
 $testReplace = str_replace(" ", ".", $test2);//doesnt work with spaces
 	 $FinalString = substr($testReplace, 0, -42);
//	 echo $FinalString;
	 $testback=strrev($FinalString);
	  $newtest=strstr($testback, '1..', true);
	
	   $NewString=strrev($newtest);
	
//try chopping off last few characots
 //$Mtext= get_string_between($Tester,"72 5 1","73 1 44");
 //echo "<br>";
// echo $Mtext;
echo $NewString;
for($x=0;$x<$counter;$x++)
            {
			 $pos2=strpos($plainTextDoc, "ACAD_MTEXT_COLUMN_INFO_BEGIN",$pos1+strlen("ACAD_MTEXT_COLUMN_INFO_BEGIN"));
			 $test1= substr($plainTextDoc, $pos2);//note frist loop fails just ignore that frist instance from abovew code
			// echo $test1;//why is
			 $test2= str_replace($test1, "",$plainTextDoc);//subtract it
		//echo $test2;
		//echo $test2;
			 $FinalString = substr($test2, 0,-42);//start here tomoro 19/11/2013//why is this different????for this one
		//	echo $FinalString;
			// $testback=strrev($test2);
		//	 $testReplace = str_replace(" ", ".", $testback);//replace with . to account for number 3 and space bug bug
		//echo  $FinalString;
			 $NewString=strrev($FinalString);
			//echo $FinalString;//cool
				// $FinalString = substr($NewString, 0,-1);//start here tomoro 19/11/2013//why is this different????for this one
		//echo $NewString;
		//	 $FinalString = substr($NewString, 0,-420);
			// echo $FinalString;
			// echo $FinalString;
			$FinalString = str_replace(" ", ".", $NewString);
			//echo $testReplace;
			// $FinalString = substr($testReplace, 250);//bunch of crap infront of string why?
		//	 echo $FinalString;//if problem with frist one do something else
			 $newtest=strstr($FinalString , '1..', true);
			// echo $newtest;
			 $NewString=strrev($newtest);//works
			
			if (isset($var)) {
			 echo"list of Mtexts";
    echo $NewString;
}
		$var="How are yeh";	
			
			// echo $FinalString;
			 $pos1=$pos2;
	        
echo "in here";			//	echo $myVar;//change big string variable use pos to credit new one
			}
}

 
$procesedData = process_file_ajax('D1.dxf');
//fix one was spanning frist thing tomoro ec 22/10/2013 //files wont work???put in .rtf at end for rich text files//Wall not working with wall change bit at top
//note wont read end of file for rtf does for .ted
//use .ted at end when I have teds installed to get it to work without teds just frist part of file is enough
//.ted gives a much higher number of offset errors
//do pad bs
//one way slab length not give in document 


?>
