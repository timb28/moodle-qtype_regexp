<?php  // $Id: questiontype.php,v 1.8  10:34 11/03/2011 joseph_rezeau Exp $

///////////////////
/// REGEXP ///
///////////////////
// Jean-Michel Vedrine & Joseph Rezeau
// based on shortanswer/questiontype 

/// QUESTION TYPE CLASS //////////////////

///
/// This class contains some special features in order to make the
/// question type embeddable within a multianswer (cloze) question
///
/**
 * @package questionbank
 * @subpackage questiontypes
 */
require_once("$CFG->dirroot/question/type/questiontype.php");

class question_regexp_qtype extends default_questiontype {

    function name() {
        return 'regexp';
    }

/*    function has_wildcards_in_responses($question, $subqid) {
        return true;
    }
*/
    function extra_question_fields() {
        return array('question_regexp', 'answers', 'usehint', 'usecase');
    }

    function questionid_column_name() {
        return 'question';
    }
/// TODO dunno what those functions are
    function move_files($questionid, $oldcontextid, $newcontextid) {
        parent::move_files($questionid, $oldcontextid, $newcontextid);
        $this->move_files_in_answers($questionid, $oldcontextid, $newcontextid);
    }

    protected function delete_files($questionid, $contextid) {
        parent::delete_files($questionid, $contextid);
        $this->delete_files_in_answers($questionid, $contextid);
    }
/// TODO dunno what those functions are --- END

    function save_question_options($question) {
        global $DB;
        $result = new stdClass;

        $context = $question->context;

        $oldanswers = $DB->get_records('question_answers',
                array('question' => $question->id), 'id ASC');

        // Insert all the new answers
        $answers = array();
        $maxfraction = -1;
        foreach ($question->answer as $key => $answerdata) {
            // Check for, and ignore, completely blank answer from the form.
            if (trim($answerdata) == '' && $question->fraction[$key] == 0 &&
                    html_is_blank($question->feedback[$key]['text'])) {
                continue;
            }

            // Update an existing answer if possible.
            $answer = array_shift($oldanswers);
            if (!$answer) {
                $answer = new stdClass();
                $answer->question = $question->id;
                $answer->answer = '';
                $answer->feedback = '';
                $answer->id = $DB->insert_record('question_answers', $answer);
            }

            $answer->answer   = trim($answerdata);
            $answer->fraction = $question->fraction[$key];
            $answer->feedback = $this->import_or_save_files($question->feedback[$key],
                    $context, 'question', 'answerfeedback', $answer->id);
            $answer->feedbackformat = $question->feedback[$key]['format'];
            $DB->update_record('question_answers', $answer);

            $answers[] = $answer->id;
            if ($question->fraction[$key] > $maxfraction) {
                $maxfraction = $question->fraction[$key];
            }
        }

        // Delete any left over old answer records.
        $fs = get_file_storage();
        foreach($oldanswers as $oldanswer) {
            $fs->delete_area_files($context->id, 'question', 'answerfeedback', $oldanswer->id);
            $DB->delete_records('question_answers', array('id' => $oldanswer->id));
        }

        $question->answers = implode(',', $answers);
        $parentresult = parent::save_question_options($question);
        if ($parentresult !== null) {
            // Parent function returns null if all is OK
            return $parentresult;
        }

        // Perform sanity checks on fractional grades
        if ($maxfraction != 1) {
            $result->noticeyesno = get_string('fractionsnomax', 'quiz', $maxfraction * 100);
            return $result;
        }

        return true;
    }

    function print_question_formulation_and_controls(&$question, &$state, $cmoptions, $options) {
        global $CFG;
        global $closestcomplete;
        // Use text services
        $textlib = textlib_get_instance();
        $context = $this->get_context_by_category_id($question->category);
        $readonly = empty($options->readonly) ? '' : 'readonly="readonly"';
        $formatoptions = new stdClass;
        $formatoptions->noclean = true;
        $formatoptions->para = false;
        $nameprefix = $question->name_prefix;
        $isadaptive = ($cmoptions->optionflags & QUESTION_ADAPTIVE);
        $ispreview = ($state->attempt == 0); // workaround to detect if question is displayed to teacher in preview popup window
        // get *first* correct answer for this question
        // rewrite this function if more answers are needed
        $correctanswers = $this->get_correct_responses($question, $state);

        /// Print question text and media

        $questiontext = format_text($question->questiontext,
                $question->questiontextformat,
                $formatoptions, $cmoptions->course);

        /// Print input controls
        if (isset($state->responses[''])) {
        	$r = $this->remove_blanks($state->responses['']); // $r = original full student response
        	$closest = array();
            $closest[0] = ''; // closest answer to be displayed as input field value
            $closest[1] = ''; // closest answer to be displayed on feedback line
            $closest[2] = ''; // hint state :: plus (added 1 letter), minus (removed extra chars & added 1 letter), complete (correct response achieved)
            $closest[3] = ''; // student's guess (rest of)

            if ($state->raw_grade == 0) {
        		$closest = $this->find_closest(&$question, &$state, &$teststate, $isadaptive, $ispreview);
        	} else {
        		$closest[0] = $r;
        		$closest[1] = $closest[0];
        	}
            $value = ' value="'.s($closest[0], true).'" ';
        } else {
            $value = ' value="" ';
        }
        $inputname = ' name="'.$nameprefix.'" ';
        $f = ''; // student's response with corrections to be displayed in feedback div
        if ( ($ispreview || $isadaptive)) {
            $f = '<span style="color:#0000FF;">'.$closest[1].'</span>'.$closest[3]."<br />"; // color blue for correct words/letters
        }
        $feedback = '';
        $class = '';
        $feedbackimg = '';

        if ($options->feedback) {
            $class = question_get_feedback_class(0);
            $feedbackimg = question_get_feedback_image(0);
            // hint has added to response one letter which makes response match one correct answer: submission is correct or partially correct
            if ($closestcomplete) {
            // we must tell $state that everything is OK
                $state->responses[''] = $closest[0];
                $state->last_graded->responses[''] = $closest[0];
                // TODO does not work for partially correct submissions
                $state->last_graded->grade = $state->raw_grade - $state->last_graded->sumpenalty;
                $state->last_graded->raw_grade = $state->raw_grade;
            }

            foreach($question->options->answers as $answer) {
                if ($this->test_response($question, $state, $answer)) {
                    // Answer was correct or partially correct.
                    $class = question_get_feedback_class($answer->fraction);
                    $feedbackimg = question_get_feedback_image($answer->fraction);
                    if ($answer->feedback) {
                        $answer->feedback = quiz_rewrite_question_urls($answer->feedback, 'pluginfile.php', $context->id, 'question', 'answerfeedback', array($state->attempt, $state->question), $answer->id);
                        $feedback = format_text($answer->feedback, $answer->feedbackformat, $formatoptions, $cmoptions->course);
                    }
                    break;
                }
            }
        }
        $feedback = $f .$feedback;
        $correctanswer = '';
        if ($options->readonly && $options->correct_responses) {
            $delimiter = '';
            if ($correctanswers) {
                foreach ($correctanswers as $ca) {
                    $correctanswer .= $delimiter.$ca;
                    $delimiter = ', ';
                }
            }
        }
        $correctanswer = stripslashes($correctanswer);
        // Removed correct answer, to be displayed later MDL-7496
        include($this->get_display_html_path());
    }

    // remove extra blank spaces from student's response
    function remove_blanks($text) {
        $pattern = "/  /"; // finds 2 successive spaces (note: \s does not work with French 'à' character! 
        while($w = preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE) ) {
            $text = substr($text, 0, $matches[0][1]) .substr($text ,$matches[0][1] + 1);
        }
        return $text;
    }

    function get_display_html_path() {
        global $CFG;
        return $CFG->dirroot.'/question/type/regexp/display.html';
    }

    function check_response(&$question, &$state) {
        foreach($question->options->answers as $aid => $answer) {
            if ($this->test_response($question, $state, $answer)) {
                return $aid;
            }
        }
        return false;
    }

    function compare_responses(&$question, &$state, &$teststate) {
        /// Use text services
        $textlib = textlib_get_instance();
        if (isset($state->responses[''])) {
            $response0 = $this->remove_blanks( stripslashes( $state->responses[''] ) );
        } else {
            $response0 = '';
        }
        if (!$response0) {
            return false;
        }
        $firstcorrectanswer = '';
        foreach ($question->options->answers as $answer) {
                $firstcorrectanswer = $answer->answer;
            break;
        }
        $r = $this->remove_blanks($state->responses['']); // $r = original full student response
        if ($r && $question->options->usehint) {
            $c = $r[strlen($r)-1];
            $d = ord($c);
                if ($d == 9) { // hint button added \t char (code 9) at end of student response
                $r = substr($r,0,strlen($r)-1) .'¶';
                $state->responses[''] = $r;
            }
        }
        if (isset($teststate->responses[''])) {
            $response1 = trim($teststate->responses['']);
        } else {
            $response1 = '';
        }

    // testing ignorecase
        $ignorecase = 'i'; // default is ignore case
        if ($question->options->usecase) {
        	$ignorecase = '';
        };
    // testing for presence of (right or wrong) elements in student's answer
        if ($response1 == $firstcorrectanswer) { // we must escape potential metacharacters in $firstcorrectanswer
            $response1 = quotemeta($teststate->responses['']);
        }

        if ( (preg_match('/^'.$response1.'$/'.$ignorecase, $response0)) ) {
            return true;
        }
    // testing for absence of needed (right) elements in student's answer, through initial -- coding
        if (substr($response1,0,2) == '--') {
            $response1 = substr($response1,2);
            // this is a NOT (a AND b AND c etc.) request
            if (preg_match('/^.*\&\&.*$/', $response1)) {
                $pattern = '/&&[^(|)]*/';
                $missingstrings = preg_match_all($pattern,$response1, $matches, PREG_OFFSET_CAPTURE);
                $strmissingstrings = $matches[0][0][0];
                $strmissingstrings = $textlib->substr($strmissingstrings, 2);
                $openparenpos = $matches[0][0][1] -1;
                $closeparenpos = $openparenpos + $textlib->strlen($strmissingstrings) + 4;
                $start = $textlib->substr($response1 , 0, $openparenpos);
                $finish = $textlib->substr($response1 , $closeparenpos);
                $missingstrings = explode ('&&', $strmissingstrings);
                foreach ($missingstrings as $missingstring) {
                    $missingstring = $start.$missingstring.$finish;
                    if (preg_match('/'.$missingstring.'/'.$ignorecase, $response0) == 0 ) {
                        return true;
                    }
                }
            } else {  // this is a NOT (a OR b OR c etc.) request
                if (preg_match('/^'.$response1.'$/'.$ignorecase, $response0)  == 0) {
                    return true;
                }
            }
        }
        return false;
    }

    function test_response(&$question, &$state, &$answer) {
    	$teststate   = clone($state);
        $teststate->responses[''] = trim($answer->answer);
            if($this->compare_responses($question, $state, $teststate)) {
                return true;
            }
        return false;
    }

    /*
     * Override the parent class method, to remove escaping from asterisks.
     */
    function get_correct_responses(&$question, &$state) {
        $response = parent::get_correct_responses($question, $state);
        if (is_array($response)) {
            $response[''] = str_replace('\*', '*', $response['']);
        }
        return $response;
    }
    /**
     * @param object $question
     * @return mixed either a integer score out of 1 that the average random
     * guess by a student might give or an empty string which means will not
     * calculate.
     */
    function get_random_guess_score($question) {
        $answers = &$question->options->answers;
        foreach($answers as $aid => $answer) {
            if ('*' == trim($answer->answer)){
                return $answer->fraction;
            }
        }
        return 0;
    }

    /**
    * Prints the score obtained and maximum score available plus any penalty
    * information
    *
    * This function prints a summary of the scoring in the most recently
    * graded state (the question may not have been submitted for marking at
    * the current state). The default implementation should be suitable for most
    * question types.
    * @param object $question The question for which the grading details are
    *                         to be rendered. Question type specific information
    *                         is included. The maximum possible grade is in
    *                         ->maxgrade.
    * @param object $state    The state. In particular the grading information
    *                          is in ->grade, ->raw_grade and ->penalty.
    * @param object $cmoptions
    * @param object $options  An object describing the rendering options.
    */
    function print_question_grading_details(&$question, &$state, $cmoptions, $options) {
        /* The default implementation prints the number of marks if no attempt
        has been made. Otherwise it displays the grade obtained out of the
        maximum grade available and a warning if a penalty was applied for the
        attempt and displays the overall grade obtained counting all previous
        responses (and penalties) */

        global $QTYPES ;
        // MDL-7496 show correct answer after "Incorrect"
        $correctanswer = '';
        if ($correctanswers =  $QTYPES[$question->qtype]->get_correct_responses($question, $state)) {
            if ($options->readonly && $options->correct_responses) {
                $delimiter = '';
                if ($correctanswers) {
                    foreach ($correctanswers as $ca) {
                        $correctanswer .= $delimiter.$ca;
                        $delimiter = ', ';
                    }
                }
            }
        }

        if (QUESTION_EVENTDUPLICATE == $state->event) {
            echo ' ';
            print_string('duplicateresponse', 'quiz');
        }
        if ($question->maxgrade > 0 && $options->scores) {
            if (question_state_is_graded($state->last_graded)) {
                // Display the grading details from the last graded state
                $grade = new stdClass;
                $grade->cur = question_format_grade($cmoptions, $state->last_graded->grade);
                $grade->max = question_format_grade($cmoptions, $question->maxgrade);
                $grade->raw = question_format_grade($cmoptions, $state->last_graded->raw_grade);
                // let student know whether the answer was correct
                $class = question_get_feedback_class($state->last_graded->raw_grade /
                        $question->maxgrade);
                echo '<div class="correctness ' . $class . '">' . get_string($class, 'quiz');                        
                if ($correctanswer  != '' && ($class == 'partiallycorrect' || $class == 'incorrect')) {
                    echo ('<div class="correctness">');
                    print_string('correctansweris', 'quiz', s($correctanswer));
                    echo ('</div>');
                }
                echo '</div>';

                echo '<div class="gradingdetails">';
                // print grade for this submission
                print_string('gradingdetails', 'quiz', $grade) ;
                // A unit penalty for numerical was applied so display it
                // a temporary solution for unit rendering in numerical
                // waiting for the new question engine code for a permanent one
                if(isset($state->options->raw_unitpenalty) && $state->options->raw_unitpenalty > 0.0 ){
                    echo ' ';
                    print_string('unitappliedpenalty','qtype_numerical',question_format_grade($cmoptions, $state->options->raw_unitpenalty ));
                }
                if ($cmoptions->penaltyscheme) {
                    // print details of grade adjustment due to penalties
                    if ($state->last_graded->raw_grade > $state->last_graded->grade){
                        echo ' ';
                        print_string('gradingdetailsadjustment', 'quiz', $grade);
                    }
                    // print info about new penalty
                    // penalty is relevant only if the answer is not correct and further attempts are possible
                    if (($state->last_graded->raw_grade < $question->maxgrade) and (QUESTION_EVENTCLOSEANDGRADE != $state->event)) {
                        if ('' !== $state->last_graded->penalty && ((float)$state->last_graded->penalty) > 0.0) {
                            echo ' ' ;
                            print_string('gradingdetailspenalty', 'quiz', question_format_grade($cmoptions, $state->last_graded->penalty));
                        } else {
                            /* No penalty was applied even though the answer was
                            not correct (eg. a syntax error) so tell the student
                            that they were not penalised for the attempt */
                            echo ' ';
                            print_string('gradingdetailszeropenalty', 'quiz');
                        }
                    }
                }
                echo '</div>';
            }
        }
    }

    function check_file_access($question, $state, $options, $contextid, $component,
            $filearea, $args) {
        if ($component == 'question' && $filearea == 'answerfeedback') {
            $answers = &$question->options->answers;
            if (isset($state->responses[''])) {
                $response = $state->responses[''];
            } else {
                $response = '';
            }
            $answerid = reset($args); // itemid is answer id.
            if (empty($options->feedback)) {
                return false;
            }
            foreach($answers as $answer) {
                if ($this->test_response($question, $state, $answer)) {
                    return true;
                }
            }
            return false;

        } else {
            return parent::check_file_access($question, $state, $options, $contextid, $component,
                    $filearea, $args);
        }
    }

    function expand_regexp($myregexp) {
        global $regexporiginal;
        $regexporiginal=$myregexp;

    // DEV il faudra peut etre revoir cette liste
        $charlist = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    // change [a-c] to [abc] NOTE: ^ metacharacter is not processed inside []
        $pattern = '/\\[\w-\w\\]/'; // find [a-c] in $myregexp
        while (preg_match($pattern, $myregexp, $matches, PREG_OFFSET_CAPTURE) ) {
            $result = $matches[0][0];
            $offset = $matches[0][1];
            $stringleft = substr($myregexp, 0, $offset +1);
            $stringright = substr($myregexp, $offset + strlen($result) -1);
            $c1 = $result[1];
            $c3 = $result[3];
            $rs = '';
            for ($c = strrpos($charlist, $c1); $c < strrpos($charlist, $c3) +1; $c++) {
                $rs.= $charlist[$c];
            }
            $myregexp = $stringleft.$rs.$stringright;

        }
    // provisionally replace existing escaped [] before processing the change [abc] to (a|b|c) JR 11-9-2007
    // see Oleg http://moodle.org/mod/forum/discuss.php?d=38542&parent=354095
        while (strpos($myregexp, '\[')) {
            $c1 = strpos($myregexp, '\[');
            $c0 = $myregexp[$c1];
            $myregexp = substr($myregexp, 0,$c1  ) .'¬' .substr($myregexp ,$c1 + 2);
        }
        while (strpos($myregexp, '\]')) {
            $c1 = strpos($myregexp, '\]');
            $c0 = $myregexp[$c1];
            $myregexp = substr($myregexp, 0,$c1  ) .'¤' .substr($myregexp ,$c1 + 2);
        }

    // change [abc] to (a|b|c)
        $pattern =  '/\[.*?\]/'; // find [abc] in $myregexp
        while (preg_match($pattern, $myregexp, $matches, PREG_OFFSET_CAPTURE) ) {
            $result = $matches[0][0];
            $offset = $matches[0][1];
            $stringleft = substr($myregexp, 0, $offset);
            $stringright = substr($myregexp, $offset + strlen($result));
            $rs = substr($result, 1, strlen($result) -2);
            $r = '';
            for ($i=0; $i < strlen($rs); $i++) {
                $r .= $rs[$i].'|';
            }
            $rs = '('.substr($r, 0, strlen($r)-1).')';
            $myregexp = $stringleft.$rs.$stringright;
        }

    // we can now safely restore the previously replaced escaped []
        while (strpos($myregexp, '¬')) {
            $c1 = strpos($myregexp, '¬');
            $c0 = $myregexp[$c1];
            $myregexp = substr($myregexp, 0,$c1  ) .'\[' .substr($myregexp ,$c1 + 2);
        }
        while (strpos($myregexp, '¤')) {
            $c1 = strpos($myregexp, '¤');
            $c0 = $myregexp[$c1];
            $myregexp = substr($myregexp, 0,$c1  ) .'\]' .substr($myregexp ,$c1 + 2);
        }

    // process ? in regexp (zero or one occurrence of preceding char)
        while (strpos($myregexp, '?')) {
            $c1 = strpos($myregexp, '?');
            $c0 = $myregexp[$c1 - 1];

    //        if \? -> escaped ?, treat as literal char (replace with ¬ char temporarily)
    //        this ¬ char chosen because non-alphanumeric & rarely used...
            if ($c0 == '\\') {
                $myregexp = substr($myregexp, 0,$c1 -1 ) .'¬' .substr($myregexp ,$c1 + 1);
                continue;
            }
    //        if )? -> meta ? action upon parens (), replace with ¤ char temporarily
    //        this ¤ char chosen because non-alphanumeric & rarely used...
            if ($c0 == ')') {
                $myregexp = substr( $myregexp, 0, $c1 -1 ) .'¤' .substr($myregexp, $c1 + 1);
                continue;
            }
        //        if ? metacharacter acts upon an escaped char, put it in $c2
            if ($myregexp[$c1 - 2] == '\\') {
                $c0 = '\\'.$c0;
            }
            $c2 = '('.$c0.'|)';
            $myregexp = str_replace($c0.'?', $c2, $myregexp);
        }
        //    replaces possible temporary ¬ char with escaped question mark
        if (strpos( $myregexp, '¬') != -1) {
            $myregexp = str_replace('¬', '\?', $myregexp);
        }
        //    replaces possible temporary ¤ char with escaped question mark
        if (strpos( $myregexp, '¤') != -1) {
            $myregexp = str_replace('¤', ')?', $myregexp);
        }

        //    process ? metacharacter acting upon a set of parentheses \(.*?\)\?
        $myregexp = str_replace(')?', '|)', $myregexp);

        //    replace escaped characters with their escape code
        while ($c = strpos($myregexp, '\\')) {
            $s1 = substr($myregexp, $c, 2);
            $s2 = $myregexp[$c + 1];
            $s2 = rawurlencode($s2);

        //        alaphanumeric chars can't be escaped; escape codes useful here are:
        //        . = %2e    ; + = %2b ; * = %2a
        //        add any others as needed & modify below accordingly
            switch ($s2) {
                case '.' : $s2 = '%2e'; break;
                case '+' : $s2 = '%2b'; break;
                case '*' : $s2 = '%2a'; break;
            }
            $myregexp = str_replace($s1, $s2, $myregexp);
        }

        //    remove starting and trailing metacharacters; not used for generation but useful for testing regexp
        if (strpos($myregexp, '^')) {
            $myregexp = substr($myregexp, 1);
        }
        if (strpos($myregexp, '$') == strlen($myregexp) -1) {
            $myregexp = substr( $myregexp, 0, strlen($myregexp) -1);
        }

        //    process metacharacters not accepted in sentence generation
        $illegalchars = array ('+','*','.','{','}');
        $illegalchar = false;
        foreach ($illegalchars as $i) {
            if (strpos($myregexp, $i)) {
                $illegalchar = true;
            }
        }
        if ($illegalchar == true) {
            echo ("<p>SORRY! Cannot generate sentences from a regExp containing one of these metacharacters: ".implode(' ', $illegalchars)."<br>If you need to use one of them in your regExp as a LITERAL character, you must \'escape\' it.<br>EXAMPLE: The [bc]at sat on the ma[pt]\\.<p>Wrong regExp = <b>$myregexp</b>");
            return ('$myregexp');
        }

        $mynewregexp = $this->find_nested_ors($myregexp); // check $myregexp for nested parentheses
        if ($mynewregexp != null) {
            $myregexp = $mynewregexp;
        }

        $result = $this->find_ors($myregexp); // expand parenthesis contents
        if ( is_array($result) ) {
            $results = implode('\n', $result);
        }
        return $result; // returns array of alternate strings
    }

    function check_my_parens($myregexp) {
        $openparen = 0;
        $closeparen = 0;
        $opensqbrack = 0;
        $closesqbrack = 0;
        $iserror = false;
        $message = '';
        for ($i = 0; $i<strlen($myregexp); $i++) {
            if ($myregexp[$i] != '\\') {
                switch ($myregexp[$i]) {
                    case '(': $openparen++; break;
                    case ')': $closeparen++; break;
                    case '[': $opensqbrack++; break;
                    case ']': $closesqbrack++; break;
                    default: break;
                }
            }
        }
        if ( ($openparen != $closeparen) || ($opensqbrack != $closesqbrack) ) {
            $iserror = true;
            $message .= get_string ('regexperror', 'qtype_regexp', $myregexp).'<br>';
        }
        if ($openparen != $closeparen) {
            $message .= get_string ('regexperrorparen', 'qtype_regexp').' - '.get_string ('regexperroropen', 'qtype_regexp', $openparen)." # ".get_string ('regexperrorclose', 'qtype_regexp', $closeparen).'<br>';
        }
        if ($opensqbrack != $closesqbrack) {
            $message .= get_string ('regexperrorsqbrack', 'qtype_regexp').' - '.get_string ('regexperroropen', 'qtype_regexp', $opensqbrack)." # ".get_string ('regexperrorclose', 'qtype_regexp', $closesqbrack);
        }
        if ($iserror) {
            return $message;
        }
        return;
    }
    // find individual $nestedors expressions in $myregexp
    function is_nested_ors ($mystring) {//return false;
        $orsstart = 0; $orsend = 0; $isnested = false; $parens = 0; $result = '';
        for ($i = 0; $i < strlen($mystring); $i++) {
            switch ($mystring[$i]) {
            case '(': 
                $parens++; 
                if ($parens == 1) {
                    $orsstart = $i;
                }
                if ($parens == 2) {
                    $isnested = true;
                }
                break;
            case ')':
                $parens--;
                if ($parens == 0) {
                    if ($isnested == true) {
                        $orsend = $i + 1; 
                        $i = strlen($mystring);
                        break;
                    } //end if
                } //end case
            } //end switch
        } // end for
        if ($isnested == true) {
            $result = substr( $mystring, $orsstart, $orsend - $orsstart);
            return $result;
        }
        return false;
    }

    // find nested parentheses
    function is_parents ($myregexp) {
        $finalresult = null;
        $pattern = '/[^(|)]*\\(([^(|)]*\\|[^(|)]*)+\\)[^(|)]*/';
        if (preg_match_all($pattern, $myregexp, $matches, PREG_OFFSET_CAPTURE)) {
        $matches = $matches[0];
            for ($i=0; $i<sizeof($matches); $i++) {
                $thisresult = $matches[$i][0];
                $leftchar = $thisresult[0];
                $rightchar = $thisresult[strlen($thisresult) -1];
                $outerchars = $leftchar .$rightchar;
                if ($outerchars !== '()') {
                    $finalresult = $thisresult;
                    break;
                }
            } // end for
        } // end if

        return $finalresult;
    }

    // find ((a|b)c)
    function find_nested_ors ($myregexp) {
    // find next nested parentheses in $myregexp
        while ($nestedors = $this->is_nested_ors ($myregexp)) {
            $nestedorsoriginal = $nestedors;

    // find what?
            while ($myparent = $this->is_parents ($nestedors)) {
                $leftchar = $nestedors[strpos($nestedors, $myparent) - 1];
                $rightchar = $nestedors[strpos($nestedors, $myparent) + strlen($myparent)];
                $outerchars = $leftchar.$rightchar;
    // il ne faut sans doute pas faire de BREAK ici...
                if ($outerchars == ')(') {
    //                break;
                }
                switch ($outerchars) {
                    case '||': 
                    case '()':
                        $leftpar = '';
                        $rightpar = '';
                        break;
                    case '((': 
                    case '))': 
                    case '(|': 
                    case '|(': 
                    case ')|':  
                    case '|)':
                        $leftpar = '('; $rightpar = ')';
                        break;
                    default:
                        break;
                }
                $t1 = $this->find_ors ($myparent);
                $t = implode('|', $t1);
                $myresult = $leftpar.$t.$rightpar;
                $nestedors = str_replace( $myparent, $myresult, $nestedors);

            }
    //    detect sequence of ((*|*)|(*|*)) within parentheses or |) or (| and remove all INSIDE parentheses
            $pattern = '/(\\(|\\|)\\([^(|)]*\\|[^(|)]*\\)(\\|\\([^(|)]*\\|[^(|)]*\\))*(\\)|\\|)/';
            while (preg_match($pattern, $nestedors, $matches, PREG_OFFSET_CAPTURE)) {
                $plainors = $matches[0][0];
                $leftchar = $plainors[0];
                $rightchar = $plainors[strlen($plainors) -1];
                $plainors2 = substr($plainors, 1, strlen($plainors) -2); // remove leading & trailing chars
                $plainors2 = str_replace(  '(',  '', $plainors2);
                $plainors2 = str_replace(  ')',  '', $plainors2);
                $plainors2 = $leftchar .$plainors2 .$rightchar;
                $nestedors = str_replace(  $plainors,  $plainors2, $nestedors);
                if ($this->is_parents($nestedors)) {
                    $myregexp = str_replace( $nestedorsoriginal, $nestedors, $myregexp);
                    continue;
                }
            }

    //        any sequence of (|)(|) in $nestedors? process them all
            $pattern = '/(\\([^(]*?\\|*?\\)){2,99}/';
            while (preg_match($pattern, $nestedors, $matches, PREG_OFFSET_CAPTURE)) {
                $parensseq = $matches[0][0];
                $myresult = $this->find_ors ($parensseq);
                $myresult = implode('|', $myresult);
                $nestedors = str_replace( $parensseq, $myresult, $nestedors);
            }
    // test if we have reached the singleOrs stage
            if ($this->is_parents ($nestedors) != null) {
                $myregexp = str_replace( $nestedorsoriginal, $nestedors, $myregexp);
                continue;
            }
    // no parents left in $nestedors, ...
    // find all single (*|*|*|*) and remove parentheses
            $patternsingleors = '/\\([^()]*\\)/';
            $patternsingleorstotal = '/^\\([^()]*\\)$/';

            while ($p = preg_match($patternsingleors, $nestedors, $matches, PREG_OFFSET_CAPTURE)) {
                $r = preg_match($patternsingleorstotal, $nestedors, $matches, PREG_OFFSET_CAPTURE);
                if ($r) {
                    if ($matches[0][0] == $nestedors) {
                        break;
                    } // we have reached top of $nestedors: keep ( )!
                }
                $r = preg_match($patternsingleors, $nestedors, $matches, PREG_OFFSET_CAPTURE);
                $singleparens = $matches[0][0];
                $myresult = substr($singleparens, 1, strlen($singleparens)-2);
                $nestedors = str_replace( $singleparens, $myresult, $nestedors);
                if ($this->is_parents ($nestedors) != null) {
                    $myregexp = str_replace( $nestedorsoriginal, $nestedors, $myregexp);
                    continue;
                }

            }
            $myregexp = str_replace( $nestedorsoriginal, $nestedors, $myregexp);

        } // end while ($nestedors = is_nested_ors ($myregexp))
        return $myregexp;
    }

    function find_ors ($mystring) {
    global $regexporiginal;

    //    add extra space between consecutive parentheses (that extra space will be removed later on)
        $pattern = '/\\(.*?\\|.*?\\)/';
        while (strpos($mystring, ')(')) {
            $mystring = str_replace( ')(', ')µ(', $mystring);
        }
        if (strpos($mystring, ')(')) {
            $mystring = str_replace( ')(', ')£(', $mystring);
        }
    //    in $mystring, find the parts outside of parentheses ($plainparts)
        $plainparts = preg_split($pattern, $mystring);
            if ($plainparts) {
                $plainparts = $this->index_plain_parts ($mystring, $plainparts);
            }
        $a = preg_match_all($pattern, $mystring, $matches, PREG_OFFSET_CAPTURE);
            if(!$a) {
                $regexporiginal = stripslashes($regexporiginal);
                return $regexporiginal;
            }
        $plainors = $this->index_ors($mystring, $matches);
    //    send $list of $plainparts and $plainors to expand_ors () function
        return($this->expand_ors ($plainparts, $plainors));
    }

    function expand_ors ($plainparts, $plainors) {//return;
    //    this function expands a chunk of words containing a single set of parenthesized alternatives
    //    of the type: <(aaa|bbb)> OR <ccc (aaa|bbb)> OR <ccc (aaa|bbb) ddd> etc.
    //    into a LIST of possible alternatives, 
    //    e.g. <ccc (aaa|bbb|)> -> <ccc aaa>, <ccc bbb>, <ccc>
        $expandedors = array();
        $expandedors[] = '';
        $slen = sizeof($expandedors);
        $expandedors[$slen-1] = '';
        if ($plainparts[0] == 0) { // if chunk begins with $plainparts
            $expandedors[$slen-1] = $plainparts[1];
            array_splice($plainparts, 0,2);
        }
        while ((sizeof($plainparts) !=0) || (sizeof($plainors) !=0)) { // go through sentence $plainparts 
            $l = sizeof($expandedors); 
            for ($k=0; $k<$l; $k++) {
                for ($m=0; $m < sizeof($plainors[1]); $m++) {
                    $expandedors[] = '';
                    $slen = sizeof($expandedors) -1;
                    $expandedors[$slen] = $expandedors[0].$plainors[1][$m]; 
                    if (sizeof($plainparts)) {
                        if ($plainparts[1]) {
                            $expandedors[$slen] .=$plainparts[1];
                        }
                    }
                    $expandedors[$slen] = rawurldecode($expandedors[$slen]);
                }
            array_splice($expandedors, 0, 1);// remove current "model" sentence from Sentences
            }
            array_splice($plainors,0,2); // remove current $plainors
            array_splice($plainparts,0,2); // remove current $plainparts

        }
    //    eliminate all extra µ signs which have been placed to replace consecutive parentheses by )µ(
            $n = count ($expandedors);
            for ($i = 0; $i < $n; $i++) {
                if (is_int(strpos($expandedors[$i], 'µ') ) ) { //corrects strpos for 1st char of a string found!
                    $expandedors[$i] = str_replace('µ', '', $expandedors[$i]);
                }
            }
        return ($expandedors);
    }

    function index_plain_parts($mystring, $plainparts) {
        $indexedplainparts = array();
        if (is_array($plainparts) ) {
            foreach($plainparts as $parts) {
                if ($parts) {
                    $index = strpos($mystring, $parts) ;
                    $indexedplainparts[] = $index;
                    $indexedplainparts[] = $parts;
                }
            }
        }
        return ($indexedplainparts);
    }

    function index_ors($mystring, $plainors) {
        $indexedplainors = array();
        foreach ($plainors as $ors) {
            foreach ($ors as $or) {
                $indexedplainors[] = $or[1];
                $o = substr($or[0], 1, strlen($or[0]) -2);
                $o = explode('|', $o);
                $indexedplainors[] = $o;
            }
        }
        return ($indexedplainors);
    }

    // functions adapted from Hot Potatoes
    function check_beginning( $guess, $answer, $ignorecase){
        if (substr($answer,0,8) == '<strong>'){ // this answer is in fact the regexp itself, do not process it
            return;
        }
        $guess = utf8_decode($guess);
        $answer = utf8_decode($answer);

        $outstring = '';
        if ($ignorecase) {
            $guessoriginal = $guess;
            $guess = strtoupper($guess);
            $answer = strtoupper($answer);
        }
        for ($i=0; ( $i<strlen($guess) && $i<strlen($answer) ) ; $i++) {
            if (strlen($answer) < $i ) {
                break;
            }
            if ($guess[$i] == $answer[$i]) {
                $outstring .= $guess[$i];
            } else {
                break;
            }
        }
        if ($ignorecase) {
            $outstring = substr($guessoriginal,0,strlen($outstring));
        }
        return $outstring;
    }

    function get_closest( $guess, $answers, $ignorecase){
        $closest[0] = ''; // closest answer to be displayed as input field value
        $closest[1] = ''; // closest answer to be displayed in feedback line
        $closest[2] = ''; // hint state :: plus (added 1 letter), minus (removed extra chars & added 1 letter), 
            //complete (correct response achieved), nil (beginning of sentence)
        $closest[3] = ''; // student's guess (rest of)
        $closesta = '';
        $ishint = 0;
        $l = strlen($guess);
        if ( substr($guess, strlen($guess)-2) == '¶' ) {
            $ishint = 1;
            $guess = substr($guess, 0, $l -2);
        }
        if ($ishint) {
            $closest[2] = 'nil';
        }
        $rightbits = array();
        foreach ( $answers as $answer) {
            $rightbits[0][] = $answer;
            $rightbits[1][] = $this->check_beginning($guess, $answer, $ignorecase);
        }
        $s = sizeof($rightbits);
        $longest = 0;
        if ($s) {
            $a = $rightbits[0];
            $s = sizeof($a);
            for ($i=0; $i<$s ;$i++) {
                $a = $rightbits[0][$i];
                $g = $rightbits[1][$i];
                if (strlen($g) > $longest) {
                    $longest = strlen($g);
                    $closesta = $g;
                    if ($ishint) {
                    	$closest[2] = 'plus';
                    	$closesta_hint = $closesta;
                        $a = utf8_decode($a); // for accents etc.
                        $closesta_hint .= substr($a,$longest, 1);
                        $lenguess = strlen($guess);
                        $lenclosesta_hint = strlen($closesta_hint) - 1;
                        if ($lenguess > $lenclosesta_hint) {
                        	$closest[2] = 'minus';
                        }
                        if (substr($a,$longest, 1) == ' ') { // if hint letter is a space, add next one
                            $closesta_hint .= substr($a,$longest + 1, 1);
                        }
                        if ( preg_match('/^'.$a.'$/'.$ignorecase, $closesta_hint) ) {
                        	$closest[2] = 'complete'; // hint gives a complete correct answer
			                $state->raw_grade = 0;
                        	break;
		                }
                    }
                }
            }
        }
        // type of hint state
        switch ($closest[2]) {
        	case 'plus':
        		$closest[0] = utf8_encode($closesta_hint);
        		$closest[1] = $closest[0];
                break;
            case 'minus':
                $closest[0] = utf8_encode($closesta_hint);
                $closest[1] = utf8_encode($closesta);
                break;
            case 'complete':
                $closest[0] = utf8_encode($a);
                $closest[1] = utf8_encode($a);
                break;
            default:
            	$closest[0] = utf8_encode($closesta);
            	$closest[1] = $closest[0];
        }

        /// search for correct words in student's guess, after closest answer has been found
        if ($closest[0] != '' && $closest[2] != 'complete') {
            $nbanswers = count ($answers);
            $lenclosesta = strlen($closest[0]);
            $minus = 0;
            if ($closest[2] == 'minus') {
            	$minus = 1;
            }
            $restofanswer = substr($guess, $lenclosesta - $minus);
            $restofanswers = '';

            for ($i=0;$i<count ($answers);$i++) {
            	if ($rightbits[1]["$i"] != '' && $rightbits[1]["$i"] == $closest[0]) {
            	   $restofanswers.= substr($rightbits[0]["$i"], $lenclosesta).' |';
            	}
            }
            if ($restofanswer) {
                $wordsinrestofanswer = split(' ', $restofanswer);	
                $i = 0;
                foreach ($wordsinrestofanswer as $word) {
					if ($word) { // just in case
					   $matches = strstr($restofanswers, $word);
						if ($matches) {
		            		$wordsinrestofanswer[$i] = '<span style="color:#FF0000;">'.$word.'</span>';
		            	} else {
		                    $wordsinrestofanswer[$i] = '<span style="text-decoration:line-through; color:#FF0000;">'.$word.'</span>';
		            	}
					}
	                $i++;
	            }
	            $guess = implode (" ", $wordsinrestofanswer);
	            $closest[3] = $guess;
            }
        }
        // absolutely nothing correct in student's guess
        if (!$closest[0] && $guess) {
        	$closest[3] = '<span style="text-decoration:line-through; color:#FF0000;">'.$guess.'</span>'; 
        }
        return $closest;
    }
    // end of functions adapted from Hot Potatoes

    // function to find whether student's response matches at least the beginning of one of the correct answers
    function find_closest(&$question, &$state, &$teststate, $isadaptive, $ispreview) {
    	global $CFG;
        global $closestcomplete;
        $closestcomplete = false;
        /// Use text services
        $textlib = textlib_get_instance();

        if (isset($state->responses[''])) {
            $response0 = $this->remove_blanks(stripslashes($state->responses['']));
        } else {
            return null;
        }
        if ( (!$isadaptive) && (!$ispreview) ) {
            return null; // no need to generate alternate answers because no hint will be needed in non-adaptive mode
        }

         // generate alternative answers for answers with score > 0%
         // this means that TEACHER MUST write answers with a > 0% grade as regexp generating alternative answers
        $correctanswers = array();
        $i = 0;
        $firstcorrectanswer = '';
        foreach ($question->options->answers as $answer) {
            if ($i == 0) {
                $i++;
                $firstcorrectanswer = $answer->answer;
                $correctanswer['answer'] = $answer->answer;
                $correctanswer['fraction'] = $answer->fraction;
                $correctanswers[] = $correctanswer;
            } else if ($answer->fraction != 0) {   
                $correctanswer['answer'] = $answer->answer;
                $correctanswer['fraction'] = $answer->fraction;
                $correctanswers[] = $correctanswer;
            }
        }
        $alternateanswers = array();
        $i=0;
        foreach ($correctanswers as $thecorrectanswer) {
            $i++;
            if ($i == 1) { 
                $alternateanswers[] = $firstcorrectanswer;
                continue;
            }
            $correctanswer = $thecorrectanswer['answer'];
            $fraction = $thecorrectanswer['fraction']*100;
            $fraction = $fraction."%"; //JR 05-10-2007

            $r = $this->expand_regexp($correctanswer);
            // if error in regular expression, expand_regexp will return nothing
            if ($r) { 
                if (is_array($r)) {
                    $alternateanswers[] = "$fraction <strong>$correctanswer</strong>";
                    $alternateanswers = array_merge($alternateanswers, $r); // normal alternateanswers
                } else {
                    $alternateanswers[] = "$fraction <strong>$r</strong>"; 
                    $alternateanswers[] = "$r"; 
                }
            }
        }

        // testing ignorecase
        $ignorecase = 'i';
        if ($question->options->usecase) {
            $ignorecase = '';
        };

    // print display button for teacher only
        if (($ispreview)) {
        	$show = get_string("showalternate", "qtype_regexp");
            echo("<input type=\"button\" value=\"$show\" onclick=\"showdiv('allanswers',this)\" />");

    // print alternate answers
            echo('<div id="allanswers" style="margin-bottom:0px; margin-top:0px; display:none;"><hr />');   
            if ($question->options->usecase) {
                $case = get_string('caseyes', 'qtype_regexp'); 
            } else {
                $case = get_string('caseno', 'qtype_regexp');
            }
            echo get_string('casesensitive', 'qtype_regexp').' : <b>'.$case.'</b><hr>';
            foreach ($alternateanswers as $answer) {
                echo("$answer<br />");
            }   
            echo("<hr /></div>");
        }

    // if student response is null (nothing typed in) then no need to go get closest correct answer
        if (!$response0) {
            return false;
        }

    // find closest answer matching student response

        $closest = $this->get_closest( $response0, $alternateanswers, $ignorecase);

        if ($closest[2] == 'complete') {
            $closestcomplete = true;
            // we need to calculate raw_grade for correct or partially correct submission obtained from Hint
            foreach($question->options->answers as $answer) {
                $thisanswer = $answer->answer;
            		if ((preg_match('/^'.$thisanswer.'$/', $closest[0]))) {
                        $state->raw_grade = $answer->fraction;
                    break;
                }
            }
            return $closest;
        }
        // give first character of firstcorrectanswer to student (if option usehint for this question)
        if ($closest[0] == '' && ($question->options->usehint == true) && $closest[2] == 'nil' ) {
            $closest[0] = $textlib->substr($firstcorrectanswer, 0, 1);
        }
        return $closest;
    }
/**
    * Provide export functionality for xml format
    * @param question object the question object
    * @param format object the format object so that helper methods can be used 
    * @param extra mixed any additional format specific data that may be passed by the format (see format code for info)
    * @return string the data to append to the output buffer or false if error
    */
/// IMPORT/EXPORT FUNCTIONS ///

    /*
     * Imports question from the Moodle XML format
     *
     * Imports question using information from extra_question_fields function
     * If some of you fields contains id's you'll need to reimplement this
     */

    /*
     * Export question to the Moodle XML format
     *
     * Export question using information from extra_question_fields function
     * If some of you fields contains id's you'll need to reimplement this
     */
    function export_to_xml($question, $format, $extra=null) {
        $expout = "    <usehint>{$question->options->usehint}</usehint>\n ";
        $expout .= "    <usecase>{$question->options->usecase}</usecase>\n ";
        foreach ($question->options->answers as $answer) {
                $percent = 100 * $answer->fraction;
                $expout .= "    <answer fraction=\"$percent\">\n";
                $expout .= $format->writetext( $answer->answer,3,false );
                $feedbackformat = $format->get_format($answer->feedbackformat);
                $expout .= "      <feedback format=\"$feedbackformat\">\n";
                $expout .= $format->writetext($answer->feedback);
                $expout .= $format->writefiles($answer->feedbackfiles);
                $expout .= "      </feedback>\n";
                $expout .= "    </answer>\n";
            }
        return $expout;
    }

   /**
    ** Provide import functionality for xml format
    ** @param data mixed the segment of data containing the question
    ** @param question object question object processed (so far) by standard import code
    ** @param format object the format object so that helper methods can be used (in particular error())
    ** @param extra mixed any additional format specific data that may be passed by the format (see format code for info)
    ** @return object question object suitable for save_options() call or false if cannot handle
    **/
    function import_from_xml($data, $question, $format, $extra=null) {
        // check question is for us///
        $qtype = $data['@']['type'];
        if ($qtype=='regexp') {
	        // copied from function import_shortanswer( $question ) in question/format/xml/format.php
	        // ATTENTION! replace all "$question" instances with "$data"
	
	        $qo = $format->import_headers( $data );
	
	        // header parts particular to regexp
	        $qo->qtype = regexp;
	
	        // get usehint
	        $qo->usehint = $format->getpath($data, array('#','usehint',0,'#'), $qo->usehint );
            // get usecase
            $qo->usecase = $format->getpath($data, array('#','usecase',0,'#'), $qo->usecase );

	        // run through the answers
	        $answers = $data['#']['answer'];
	        $a_count = 0;
	        foreach ($answers as $answer) {
	            $ans = $format->import_answer($answer);
	            $qo->answer[$a_count] = $ans->answer['text'];
	            $qo->fraction[$a_count] = $ans->fraction;
	            $qo->feedback[$a_count] = $ans->feedback;
	            ++$a_count;
	        }
	       return $qo;
        } else {
            return false;
        }
    }

    /**
     * Print history of responses
     *
     * Used by print_question()
     */
    /// devjr needs to be overridden to change tab char back into human-readeable [hint] label
    function history($question, $state, $number, $cmoptions, $options) {
        global $DB, $OUTPUT;
        if (empty($options->history)) {
            return '';
        }

        if (isset($question->randomquestionid)) {
            $actualquestionid = $question->randomquestionid;
            $randomprefix = 'random' . $question->id . '-';
        } else {
            $actualquestionid = $question->id;
            $randomprefix = '';
        }
        if ($options->history == 'all') {
            $eventtest = 'event > 0';
        } else {
            $eventtest = 'event IN (' . QUESTION_EVENTS_GRADED . ')';
        }
        $states = $DB->get_records_select('question_states',
                'attempt = :aid AND question = :qid AND ' . $eventtest,
                array('aid' => $state->attempt, 'qid' => $actualquestionid), 'seq_number,id');
        if (count($states) <= 1) {
            return '';
        }

        $strreviewquestion = get_string('reviewresponse', 'quiz');
        $table = new html_table();
        $table->width = '100%';
        $table->head  = array (
            get_string('numberabbr', 'quiz'),
            get_string('action', 'quiz'),
            get_string('response', 'quiz'),
            get_string('time'),
        );
        if ($options->scores) {
            $table->head[] = get_string('score', 'quiz');
            $table->head[] = get_string('grade', 'quiz');
        }

        foreach ($states as $st) {
            if ($randomprefix && strpos($st->answer, $randomprefix) === 0) {
                $st->answer = substr($st->answer, strlen($randomprefix));
            }
            // Hint was used by student
            if ( substr($st->answer, strlen($st->answer)-2) == '¶' ) {
            	$st->answer = substr($st->answer, 0, strlen($st->answer)-2).
            	   ' <span style="color:#FF0000;">['.get_string ('hint', 'qtype_regexp').']</span>';
            }
            // end Hint re-writing
            $st->responses[''] = $st->answer;
            $this->restore_session_and_responses($question, $st);

            if ($state->id == $st->id) {
                $link = '<b>' . $st->seq_number . '</b>';
            } else if (isset($options->questionreviewlink)) {
                $reviewlink = new moodle_url($options->questionreviewlink);
                $reviewlink->params(array('state' => $st->id,'question' => $actualquestionid));
                $link = new moodle_url($reviewlink);
                $action = new popup_action('click', $link, 'reviewquestion', array('height' => 450, 'width' => 650));
                $link = $OUTPUT->action_link($link, $st->seq_number, $action, array('title'=>$strreviewquestion));
            } else {
                $link = $st->seq_number;
            }

            if ($state->id == $st->id) {
                $b = '<b>';
                $be = '</b>';
            } else {
                $b = '';
                $be = '';
            }
            $data = array (
                $link,
                $b.get_string('event'.$st->event, 'quiz').$be,
                $b.$this->response_summary($question, $st, $length = 80, $formatting =false).$be, // set formatting to false to display Hint color
                $b.userdate($st->timestamp, get_string('timestr', 'quiz')).$be,
            );
            if ($options->scores) {
                $data[] = $b.question_format_grade($cmoptions, $st->raw_grade).$be;
                $data[] = $b.question_format_grade($cmoptions, $st->raw_grade).$be;
            }
            $table->data[] = $data;
        }
        return html_writer::table($table);
    }
}
//// END OF CLASS ////

//////////////////////////////////////////////////////////////////////////
//// INITIATION - Without this line the question type is not in use... ///
//////////////////////////////////////////////////////////////////////////
question_register_questiontype(new question_regexp_qtype());