<?php

/*
 * File: ste.php
 * The implementation of the STE Template Engine.
 *
 * About: License
 * This file is licensed under the MIT/X11 License.
 * See COPYING for more details.
 */

/*
 * Namespace: ste
 * Everything in this file is in this namespace.
 */
namespace ste;

abstract class ASTNode {
    public $tpl;
    public $offset;
    public function __construct($tpl, $off) {
        $this->tpl    = $tpl;
        $this->offset = $off;
    }
}

class TextNode extends ASTNode {
    public $text;
    public function __construct($tpl, $off, $text = "") {
        parent::__construct($tpl, $off);
        $this->text = $text;
    }
}

class TagNode extends ASTNode {
    public $name;
    public $params = array();
    public $sub = array();
    public function __construct($tpl, $off, $name = "") {
        parent::__construct($tpl, $off);
        $this->name = $name;
    }
}

class VariableNode extends ASTNode {
    public $name;
    public $arrayfields = array();
    public function transcompile() {
        $varaccess = '@$ste->vars[' . (is_numeric($this->name) ? $this->name : '"' . escape_text($this->name) . '"'). ']';
        foreach($this->arrayfields as $af) {
            if((count($af) == 1) and ($af[0] instanceof TextNode) and is_numeric($af[0]->text)) {
                $varaccess .= '[' . $af->text . ']';
            } else {
                $varaccess .= '[' . implode(".", array_map(function($node) {
                    if($node instanceof TextNode) {
                        return "\"" . escape_text($node->text) . "\"";
                    } else if($node instanceof VariableNode) {
                        return $node->transcompile();
                    }
                }, $af)). ']';
            }
        }
        return $varaccess;
    }
}

class ParseCompileError extends \Exception {
    public $msg;
    public $tpl;
    public $off;

    public function __construct($msg, $tpl, $offset, $code = 0, $previous = NULL) {
        $this->msg = $msg;
        $this->tpl = $tpl;
        $this->off = $offset;
        $this->message = "$msg (Template $tpl, Offset $offset)";
    }

    public function rewrite($code) {
        $line = substr_count(str_replace("\r\n", "\n", substr($code, 0, $this->off)), "\n") + 1;
        $this->message = "{$this->msg} (Template {$this->tpl}, Line $line)";
        $this->is_rewritten = true;
    }
}

/*
 * Class: RuntimeError
 * An Exception that a tag can throw, if a non-fatal runtime error occurred.
 * By default this will return in no output at all. But if <STECore::$mute_runtime_errors> is false, this will generate a error message instead of the tag's output.
 */
class RuntimeError      extends \Exception {}

/*
 * Class: FatalRuntimeError
 * An Exception a tag can throw, if a fatal (irreparable) runtime error occurred.
 * This Exception will always "bubble up" so you probably want to catch them. Remember that this exception is also in the namespace ste!
 */
class FatalRuntimeError extends \Exception {}

/*
 * Class: Parser
 * The class, where the parser lives in. Can not be constructed manually.
 * Use the static method parse.
 */
class Parser {
    private $text;
    private $name;
    private $off;
    private $len;

    const PARSE_SHORT = 1;
    const PARSE_TAG   = 2;

    const ESCAPES_DEFAULT = '$?~{}|\\';

    private function __construct($text, $name) {
        $this->text = $text;
        $this->name = $name;
        $this->off  = 0;
        $this->len  = mb_strlen($text);
    }

    private function next($n = 1) {
        if($n <= 0) {
            throw new \InvalidArgumentException("\$n must be > 0");
        }
        $c = mb_substr($this->text, $this->off, $n);
        $this->off = min($this->off + $n, $this->len);
        return $c;
    }


    private function back($n = 1) {
        if($n <= 0) {
            throw new \InvalidArgumentException("\$n must be > 0");
        }
        $this->off = max($this->off - $n, 0);
    }

    private function search_off($needle) {
        return mb_strpos($this->text, $needle, $this->off);
    }

    private function search_multi($needles) {
        $oldoff = $this->off;

        $minoff = $this->len;
        $which = NULL;

        foreach($needles as $key => $needle) {
            if(($off = $this->search_off($needle)) === false) {
                continue;
            }

            if($off < $minoff) {
                $minoff = $off;
                $which = $key;
            }
        }

        $this->off = $minoff + (($which === NULL) ? 0 : mb_strlen((string) $needles[$which]));

        return array($which, $minoff, mb_substr($this->text, $oldoff, $minoff - $oldoff), $oldoff);
    }

    private function search($needle) {
        $oldoff = $this->off;

        $off = $this->search_off($needle);
        if($off === false) {
            $this->off = $this->len;
            return array(false, mb_substr($this->text, $oldoff), $oldoff);
        }

        $this->off = $off + mb_strlen($needle);
        return array($off, mb_substr($this->text, $oldoff, $off - $oldoff), $oldoff);
    }

    private function take_while($cb) {
        $s = "";
        while($c = $this->next()) {
            if(!call_user_func($cb, $c)) {
                $this->back();
                return $s;
            }
            $s .= $c;
        }
        return $s;
    }

    private function skip_ws() {
        $this->take_while("ctype_space");
    }

    private function get_name() {
        $off = $this->off;
        $name = $this->take_while(function($c) { return ctype_alnum($c) || ($c == "_"); });
        if(mb_strlen($name) == 0) {
            throw new ParseCompileError("Expected a name (alphanumeric chars + '_', at least one char)", $this->name, $off);
        }
        return $name;
    }

    /*
     * Function: parse
     * Parses the input into an AST.
     *
     * You only need this function, if you want to manually trnascompile a template.
     *
     * Parameters:
     *  $text - The input code.
     *  $name - The name of the template.
     *
     * Returns:
     *  An array of <ASTNode> objects.
     *
     * Throws:
     *  <ParseCompileError>
     */
    public static function parse($text, $name) {
        $obj = new self($text, $name);
        $res = $obj->parse_text(
            self::ESCAPES_DEFAULT, /* Escapes */
            self::PARSE_SHORT | self::PARSE_TAG /* Flags */
        );
        return self::tidyup_ast($res[0]);
    }

    private static function tidyup_ast($ast) {
        $out = array();

        $prevtext = NULL;
        $first = true;

        foreach($ast as $node) {
            if($node instanceof TextNode) {
                if($prevtext === NULL) {
                    $prevtext = $node;
                } else {
                    $prevtext->text .= $node->text;
                }
            } else {
                if($prevtext !== NULL) {
                    if($first) {
                        $prevtext->text = ltrim($prevtext->text);
                    }
                    if($prevtext->text != "") {
                        $out[] = $prevtext;
                    }
                }
                $prevtext = NULL;
                $first = false;

                if($node instanceof TagNode) {
                    $node->sub = self::tidyup_ast($node->sub);
                    foreach($node->params as $k => &$v) {
                        $v = self::tidyup_ast($v);
                    }
                    unset($v);
                } else { /* VariableNode */
                    foreach($node->arrayfields as &$v) {
                        $v = self::tidyup_ast($v);
                    }
                    unset($v);
                }

                $out[] = $node;
            }
        }

        if($prevtext !== NULL) {
            if($first) {
                $prevtext->text = ltrim($prevtext->text);
            }
            if($prevtext->text != "") {
                $out[] = $prevtext;
            }
        }

        return $out;
    }

    private function parse_text($escapes, $flags, $breakon = NULL, $separator = NULL, $nullaction = NULL, $opentag = NULL, $openedat = -1) {
        $elems = array();
        $astlist = array();

        $needles = array(
            "commentopen" => "<ste:comment>",
            "rawopen" => "<ste:rawtext>",
            "escape" => '\\',
            "varcurlyopen" => '${',
            "var" => '$',
        );

        if($flags & self::PARSE_TAG) {
            $needles["tagopen"] = '<ste:';
            $needles["closetagopen"] = '</ste:';
        }
        if($flags & self::PARSE_SHORT) {
            $needles["shortifopen"] = '?{';
            $needles["shortcompopen"] = '~{';
        }

        if($separator !== NULL) {
            $needles["sep"] = $separator;
        }
        if($breakon !== NULL) {
            $needles["break"] = $breakon;
        }

        for(;;) {
            list($which, $off, $before, $offbefore) = $this->search_multi($needles);

            $astlist[] = new TextNode($this->name, $offbefore, $before);

            switch($which) {
            case NULL:
                if($nullaction === NULL) {
                    $elems[] = $astlist;
                    return $elems;
                } else {
                    call_user_func($nullaction);
                }
                break;
            case "commentopen":
                list($off, $before, $offbefore) = $this->search("</ste:comment>");
                if($off === false) {
                    throw new ParseCompileError("ste:comment was not closed", $this->name, $offbefore);
                }
                break;
            case "rawopen":
                $off_start = $off;
                list($off, $before, $offbefore) = $this->search("</ste:rawtext>");
                if($off === false) {
                    throw new ParseCompileError("ste:rawtext was not closed", $this->name, $off_start);
                }
                $astlist[] = new TextNode($this->name, $off_start, $before);
                break;
            case "tagopen":
                $astlist[] = $this->parse_tag($off);
                break;
            case "closetagopen":
                $off_start = $off;
                $name = $this->get_name();
                $this->skip_ws();
                $off = $this->off;
                if($this->next() != ">") {
                    throw new ParseCompileError("Expected '>' in closing ste-Tag", $this->name, $off);
                }

                if($opentag === NULL) {
                    throw new ParseCompileError("Found closing ste:$name tag, but no tag was opened", $this->name, $off_start);
                }
                if($opentag != $name) {
                    throw new ParseCompileError("Open ste:$opentag was not closed", $this->name, $openedat);
                }

                $elems[] = $astlist;
                return $elems;
            case "escape":
                $c = $this->next();
                if(mb_strpos($escapes, $c) !== false) {
                    $astlist[] = new TextNode($this->name, $off, $c);
                } else {
                    $astlist[] = new TextNode($this->name, $off, '\\');
                    $this->back();
                }
                break;
            case "shortifopen":
                $shortelems = $this->parse_short("?{", $off);
                if(count($shortelems) != 3) {
                    throw new ParseCompileError("A short if tag must have the form ?{..|..|..}", $this->name, $off);
                }

                list($cond, $then, $else) = $shortelems;
                $thentag = new TagNode($this->name, $off);
                $thentag->name = "then";
                $thentag->sub = $then;

                $elsetag = new TagNode($this->name, $off);
                $elsetag->name = "else";
                $elsetag->sub = $else;

                $iftag = new TagNode($this->name, $off);
                $iftag->name = "if";
                $iftag->sub = $cond;
                $iftag->sub[] = $thentag;
                $iftag->sub[] = $elsetag;

                $astlist[] = $iftag;
                break;
            case "shortcompopen":
                $shortelems = $this->parse_short("~{", $off);
                if(count($shortelems) != 3) {
                    throw new ParseCompileError("A short comparasion tag must have the form ~{..|..|..}", $this->name, $off);
                }

                // TODO: What will happen, if a tag was in one of the elements?
                list($a, $op, $b) = $shortelems;
                $cmptag = new TagNode($this->name, $off);
                $cmptag->name = "cmp";
                $cmptag->params["text_a"] = $a;
                $cmptag->params["op"] = $op;
                $cmptag->params["text_b"] = $b;

                $astlist[] = $cmptag;
                break;
            case "sep":
                $elems[] = $astlist;
                $astlist = array();
                break;
            case "varcurlyopen":
                $astlist[] = $this->parse_var($off, true);
                break;
            case "var":
                $astlist[] = $this->parse_var($off, false);
                break;
            case "break":
                $elems[] = $astlist;
                return $elems;
            }
        }

        $elems[] = $astlist;
        return $elems;
    }

    private function parse_short($shortname, $openedat) {
        $tplname = $this->name;

        return $this->parse_text(
            self::ESCAPES_DEFAULT, /* Escapes */
            self::PARSE_SHORT | self::PARSE_TAG, /* Flags */
            '}', /* Break on */
            '|', /* Separator */
            function() use ($shortname, $tplname, $openedat) { /* NULL action */
                throw new ParseCompileError("Unclosed $shortname", $tplname, $openedat);
            },
            NULL, /* Open tag */
            $openedat /* Opened at */
        );
    }

    private function parse_var($openedat, $curly) {
        $varnode = new VariableNode($this->name, $openedat);
        $varnode->name = $this->get_name();
        $varnode->arrayfields = $this->parse_array();

        if(($curly) && ($this->next() != "}")) {
            throw new ParseCompileError("Unclosed '\${'", $this->name, $openedat);
        }
        return $varnode;
    }

    private function parse_array() {
        $tplname = $this->name;

        $arrayfields = array();

        while($this->next() == "[") {
            $openedat = $this->off - 1;
            $res = $this->parse_text(
                self::ESCAPES_DEFAULT, /* Escapes */
                0, /* Flags */
                ']', /* Break on */
                NULL, /* Separator */
                function() use ($tplname, $openedat) { /* NULL action */
                    throw new ParseCompileError("Unclosed array access '[...]'", $tplname, $openedat);
                },
                NULL, /* Open tag */
                $openedat /* Opened at */
            );
            $arrayfields[] = $res[0];
        }

        $this->back();
        return $arrayfields;
    }

    private function parse_tag($openedat) {
        $tplname = $this->name;

        $this->skip_ws();
        $tag = new TagNode($this->name, $openedat);
        $name = $tag->name = $this->get_name();
        $tag->params = array();
        $tag->sub = array();

        for(;;) {
            $this->skip_ws();

            switch($this->next()) {
            case '/': /* Self-closing tag */
                $this->skip_ws();
                if($this->next() != '>') {
                    throw new ParseCompileError("Unclosed opening <ste: tag (expected >)", $this->name, $openedat);
                }

                return $tag;
            case '>':
                $sub = $this->parse_text(
                    self::ESCAPES_DEFAULT, /* Escapes */
                    self::PARSE_SHORT | self::PARSE_TAG, /* Flags */
                    NULL, /* Break on */
                    NULL, /* Separator */
                    function() use ($name, $tplname, $openedat) { /* NULL action */
                        throw new ParseCompileError("Open ste:$name tag was not closed", $tplname, $openedat);
                    },
                    $tag->name, /* Open tag */
                    $openedat /* Opened at */
                );
                $tag->sub = $sub[0];
                return $tag;
            default:
                $this->back();

                $param = $this->get_name();

                $this->skip_ws();
                if($this->next() != '=') {
                    throw new ParseCompileError("Expected '=' after tag parameter name", $this->name, $this->off - 1);
                }
                $this->skip_ws();

                $quot = $this->next();
                if(($quot != '"') && ($quot != "'")) {
                    throw new ParseCompileError("Expected ' or \" after '=' of tag parameter", $this->name, $this->off - 1);
                }

                $off = $this->off - 1;
                $paramval = $this->parse_text(
                    self::ESCAPES_DEFAULT . $quot, /* Escapes */
                    0, /* Flags */
                    $quot, /* Break on */
                    NULL, /* Separator */
                    function() use ($quot, $tplname, $off) { /* NULL action */
                        throw new ParseCompileError("Open tag parameter value ($quot) was not closed", $tplname, $off);
                    },
                    NULL, /* Open tag */
                    $off /* Opened at */
                );
                $tag->params[$param] = $paramval[0];
            }
        }
    }
}

function indent_code($code) {
    return implode(
        "\n",
        array_map(function($line) { return "\t$line"; }, explode("\n", $code))
    );
}

/* We could also just eval() the $infix_math code, but this is much cooler :-D (Parser inception) */
function shunting_yard($infix_math) {
    $operators = array(
        "+" => array("l", 2),
        "-" => array("l", 2),
        "*" => array("l", 3),
        "/" => array("l", 3),
        "^" => array("r", 4),
        "_" => array("r", 5),
        "(" => array("", 0),
        ")" => array("", 0)
    );

    preg_match_all("/\s*(?:(?:[+\\-\\*\\/\\^\\(\\)])|(\\d*[\\.]?\\d*))\\s*/s", $infix_math, $tokens, PREG_PATTERN_ORDER);
    $tokens_raw   = array_filter(array_map('trim', $tokens[0]), function($x) { return ($x === "0") || (!empty($x)); });
    $output_queue = array();
    $op_stack     = array();

    $lastpriority = NULL;
    /* Make - unary, if neccessary */
    $tokens = array();
    foreach($tokens_raw as $token) {
        $priority = isset($operators[$token]) ? $operators[$token][1] : -1;
        if(($token == "-") && (($lastpriority === NULL) || ($lastpriority >= 0))) {
            $priority = $operators["_"][1];
            $tokens[] = "_";
        } else {
            $tokens[] = $token;
        }
        $lastpriority = $priority;
    }

    while(!empty($tokens)) {
        $token = array_shift($tokens);
        if(is_numeric($token)) {
            $output_queue[] = $token;
        } else if($token == "(") {
            $op_stack[] = $token;
        } else if($token == ")") {
            $lbr_found = false;
            while(!empty($op_stack)) {
                $op = array_pop($op_stack);
                if($op == "(") {
                    $lbr_found = true;
                    break;
                }
                $output_queue[] = $op;
            }
            if(!$lbr_found) {
                throw new RuntimeError("Bracket mismatch.");
            }
        } else if(!isset($operators[$token])) {
            throw new RuntimeError("Invalid token ($token): Not a number, bracket or operator. Stop.");
        } else {
            $priority = $operators[$token][1];
            if($operators[$token][0] == "l") {
                while((!empty($op_stack)) and ($priority <= $operators[$op_stack[count($op_stack)-1]][1])) {
                    $output_queue[] = array_pop($op_stack);
                }
            } else {
                while((!empty($op_stack)) and ($priority < $operators[$op_stack[count($op_stack)-1]][1])) {
                    $output_queue[] = array_pop($op_stack);
                }
            }
            $op_stack[] = $token;
        }
    }

    while(!empty($op_stack)) {
        $op = array_pop($op_stack);
        if($op == "(") {
            throw new RuntimeError("Bracket mismatch...");
        }
        $output_queue[] = $op;
    }

    return $output_queue;
}

function pop2(&$array) {
    $rv = array(array_pop($array), array_pop($array));
    if(array_search(NULL, $rv, true) !== false) {
        throw new RuntimeError("Not enough numbers on stack. Invalid formula.");
    }
    return $rv;
}

function calc_rpn($rpn) {
    $stack = array();
    foreach($rpn as $token) {
        switch($token) {
        case "+":
            list($b, $a) = pop2($stack);
            $stack[] = $a + $b;
            break;
        case "-":
            list($b, $a) = pop2($stack);
            $stack[] = $a - $b;
            break;
        case "*":
            list($b, $a) = pop2($stack);
            $stack[] = $a * $b;
            break;
        case "/":
            list($b, $a) = pop2($stack);
            $stack[] = $a / $b;
            break;
        case "^":
            list($b, $a) = pop2($stack);
            $stack[] = pow($a, $b);
            break;
        case "_":
            $a = array_pop($stack);
            if($a === NULL) {
                throw new RuntimeError("Not enough numbers on stack. Invalid formula.");
            }
            $stack[] = -$a;
            break;
        default:
            $stack[] = $token;
            break;
        }
    }
    return array_pop($stack);
}

function loopbody($code) {
    return "try\n{\n" . indent_code($code) . "\n}\ncatch(\ste\BreakException \$e) { break; }\ncatch(\ste\ContinueException \$e) { continue; }\n";
}

$ste_builtins = array(
    "if" => function($ast) {
        $output = "";
        $condition = array();
        $then = NULL;
        $else = NULL;

        foreach($ast->sub as $node) {
            if(($node instanceof TagNode) and ($node->name == "then")) {
                $then = $node->sub;
            } else if(($node instanceof TagNode) and ($node->name == "else")) {
                $else = $node->sub;
            } else {
                $condition[] = $node;
            }
        }

        if($then === NULL) {
            throw new ParseCompileError("Transcompile error: Missing <ste:then> in <ste:if>.", $ast->tpl, $ast->offset);
        }

        $output .= "\$outputstack[] = \"\";\n\$outputstack_i++;\n";
        $output .= _transcompile($condition);
        $output .= "\$outputstack_i--;\nif(\$ste->evalbool(array_pop(\$outputstack)))\n{\n";
        $output .= indent_code(_transcompile($then));
        $output .= "\n}\n";
        if($else !== NULL) {
            $output .= "else\n{\n";
            $output .= indent_code(_transcompile($else));
            $output .= "\n}\n";
        }
        return $output;
    },
    "cmp" => function($ast) {
        $operators = array(
            array('eq', '=='),
            array('neq', '!='),
            array('lt', '<'),
            array('lte', '<='),
            array('gt', '>'),
            array('gte', '>=')
        );

        $code = "";

        if(isset($ast->params["var_b"])) {
            list($val, $pre) = _transcompile($ast->params["var_b"], true);
            $code .= $pre;
            $b = '$ste->get_var_by_name(' . $val . ')';
        } else if(isset($ast->params["text_b"])) {
            list($b, $pre) = _transcompile($ast->params["text_b"], true);
            $code .= $pre;
        } else {
            throw new ParseCompileError("Transcompile error: neiter var_b nor text_b set in <ste:cmp>.", $ast->tpl, $ast->offset);
        }

        if(isset($ast->params["var_a"])) {
            list($val, $pre) = _transcompile($ast->params["var_a"], true);
            $code .= $pre;
            $a = '$ste->get_var_by_name(' . $val . ')';
        } else if(isset($ast->params["text_a"])) {
            list($a, $pre) = _transcompile($ast->params["text_a"], true);
            $code .= $pre;
        } else {
            throw new ParseCompileError("Transcompile error: neiter var_a nor text_a set in <ste:cmp>.", $ast->tpl, $ast->offset);
        }

        if(!isset($ast->params["op"])) {
            throw new ParseCompileError("Transcompile error: op not given in <ste:cmp>.", $ast->tpl, $ast->offset);
        }
        if((count($ast->params["op"]) == 1) and ($ast->params["op"][0] instanceof TextNode)) {
            /* Operator is known at compile time, this saves *a lot* of output code! */
            $op = trim($ast->params["op"][0]->text);
            $op_php = NULL;
            foreach($operators as $v) {
                if($v[0] == $op) {
                    $op_php = $v[1];
                    break;
                }
            }
            if($op_php === NULL) {
                throw new ParseCompileError("Transcompile Error: Unknown operator in <ste:cmp>", $ast->tpl, $ast->offset);
            }
            $code .= "\$outputstack[\$outputstack_i] .= (($a) $op_php ($b)) ? 'yes' : '';\n";
        } else {
            list($val, $pre) = _transcompile($ast->params["op"], true);
            $code .= $pre . "switch(trim(" . $val . "))\n{\n\t";
            $code .= implode("", array_map(
                    function($op) use ($a,$b)
                    {
                        list($op_stetpl, $op_php) = $op;
                        return "case '$op_stetpl':\n\t\$outputstack[\$outputstack_i] .= (($a) $op_php ($b)) ? 'yes' : '';\n\tbreak;\n\t";
                    }, $operators
                ));
            $code .= "default: throw new \\ste\\RuntimeError('Unknown operator in <ste:cmp>.');\n}\n";
        }
        return $code;
    },
    "not" => function($ast) {
        $code = "\$outputstack[] = '';\n\$outputstack_i++;\n";
        $code .= _transcompile($ast->sub);
        $code .= "\$outputstack_i--;\n\$outputstack[\$outputstack_i] .= (!\$ste->evalbool(array_pop(\$outputstack))) ? 'yes' : '';\n";
        return $code;
    },
    "even" => function($ast) {
        $code = "\$outputstack[] = '';\n\$outputstack_i++;\n";
        $code .= _transcompile($ast->sub);
        $code .= "\$outputstack_i--;\n\$tmp_even = array_pop(\$outputstack);\n\$outputstack[\$outputstack_i] .= (is_numeric(\$tmp_even) and (\$tmp_even % 2 == 0)) ? 'yes' : '';\n";
        return $code;
    },
    "for" => function($ast) {
        $code = "";
        $loopname = "forloop_" . str_replace(".", "_", uniqid("",true));
        if(empty($ast->params["start"])) {
            throw new ParseCompileError("Transcompile error: Missing 'start' parameter in <ste:for>.", $ast->tpl, $ast->offset);
        }
        list($val, $pre) = _transcompile($ast->params["start"], true);
        $code .= $pre;
        $code .= "\$${loopname}_start = " . $val . ";\n";

        if(empty($ast->params["stop"])) {
            throw new ParseCompileError("Transcompile error: Missing 'end' parameter in <ste:for>.", $ast->tpl, $ast->offset);
        }
        list($val, $pre) = _transcompile($ast->params["stop"], true);
        $code .= $pre;
        $code .= "\$${loopname}_stop = " . $val . ";\n";

        $step = NULL; /* i.e. not known at compilation time */
        if(empty($ast->params["step"])) {
            $step = 1;
        } else if((count($ast->params["step"]) == 1) and ($ast->params["step"][0] instanceof TextNode)) {
            $step = $ast->params["step"][0]->text + 0;
        } else {
            list($val, $pre) = _transcompile($ast->params["step"], true);
            $code .= $pre;
            $code .= "\$${loopname}_step = " . $val . ";\n";
        }

        if(!empty($ast->params["counter"])) {
            list($val, $pre) = _transcompile($ast->params["counter"], true);
            $code .= $pre;
            $code .= "\$${loopname}_countername = " . $val . ";\n";
        }

        $loopbody = empty($ast->params["counter"]) ? "" : "\$ste->set_var_by_name(\$${loopname}_countername, \$${loopname}_counter);\n";
        $loopbody .= _transcompile($ast->sub);
        $loopbody = indent_code("{\n" . loopbody(indent_code($loopbody)) . "\n}\n");

        if($step === NULL) {
            $code .= "if(\$${loopname}_step == 0)\n\tthrow new \\ste\\RuntimeError('step can not be 0 in <ste:for>.');\n";
            $code .= "if(\$${loopname}_step > 0)\n{\n";
            $code .= "\tfor(\$${loopname}_counter = \$${loopname}_start; \$${loopname}_counter <= \$${loopname}_stop; \$${loopname}_counter += \$${loopname}_step)\n";
            $code .= $loopbody;
            $code .= "\n}\nelse\n{\n";
            $code .= "\tfor(\$${loopname}_counter = \$${loopname}_start; \$${loopname}_counter >= \$${loopname}_stop; \$${loopname}_counter += \$${loopname}_step)\n";
            $code .= $loopbody;
            $code .= "\n}\n";
        } else if($step == 0) {
            throw new ParseCompileError("Transcompile Error: step can not be 0 in <ste:for>.", $ast->tpl, $ast->offset);
        } else if($step > 0) {
            $code .= "for(\$${loopname}_counter = \$${loopname}_start; \$${loopname}_counter <= \$${loopname}_stop; \$${loopname}_counter += $step)\n$loopbody\n";
        } else {
            $code .= "for(\$${loopname}_counter = \$${loopname}_start; \$${loopname}_counter >= \$${loopname}_stop; \$${loopname}_counter += $step)\n$loopbody\n";
        }

        return $code;
    },
    "foreach" => function($ast) {
        $loopname = "foreachloop_" . str_replace(".", "_", uniqid("",true));
        $code = "";

        if(empty($ast->params["array"])) {
            throw new ParseCompileError("Transcompile Error: array not given in <ste:foreach>.", $ast->tpl, $ast->offset);
        }
        list($val, $pre) = _transcompile($ast->params["array"], true);
        $code .= $pre;
        $code .= "\$${loopname}_arrayvar = " . $val . ";\n";

        if(empty($ast->params["value"])) {
            throw new ParseCompileError("Transcompile Error: value not given in <ste:foreach>.", $ast->tpl, $ast->offset);
        }
        list($val, $pre) = _transcompile($ast->params["value"], true);
        $code .= $pre;
        $code .= "\$${loopname}_valuevar = " . $val . ";\n";

        if(!empty($ast->params["key"])) {
            list($val, $pre) = _transcompile($ast->params["key"], true);
            $code .= $pre;
            $code .= "\$${loopname}_keyvar = " . $val . ";\n";
        }

        if(!empty($ast->params["counter"])) {
            list($val, $pre) = _transcompile($ast->params["counter"], true);
            $code .= $pre;
            $code .= "\$${loopname}_countervar = " . $val . ";\n";
        }

        $loopbody = "";
        $code .= "\$${loopname}_array = \$ste->get_var_by_name(\$${loopname}_arrayvar);\n";
        $code .= "if(!is_array(\$${loopname}_array))\n\t\$${loopname}_array = array();\n";
        if(!empty($ast->params["counter"])) {
            $code .= "\$${loopname}_counter = -1;\n";
            $loopbody .= "\$${loopname}_counter++;\n\$ste->set_var_by_name(\$${loopname}_countervar, \$${loopname}_counter);\n";
        }

        $loop = array();
        $else = array();
        foreach($ast->sub as $node) {
            if(($node instanceof TagNode) && ($node->name == "else")) {
                $else = array_merge($else, $node->sub);
            } else {
                $loop[] = $node;
            }
        }

        $loopbody .= "\$ste->set_var_by_name(\$${loopname}_valuevar, \$${loopname}_value);\n";
        if(!empty($ast->params["key"])) {
            $loopbody .= "\$ste->set_var_by_name(\$${loopname}_keyvar, \$${loopname}_key);\n";
        }
        $loopbody .= "\n";
        $loopbody .= _transcompile($loop);
        $loopbody = "{\n" . loopbody(indent_code($loopbody)) . "\n}\n";

        if(!empty($else)) {
            $code .= "if(empty(\$${loopname}_array))\n{\n";
            $code .= indent_code(_transcompile($else));
            $code .= "\n}\nelse ";
        }
        $code .= "foreach(\$${loopname}_array as \$${loopname}_key => \$${loopname}_value)\n$loopbody\n";

        return $code;
    },
    "infloop" => function($ast) {
        return "while(true)\n{\n" . indent_code(loopbody(indent_code(_transcompile($ast->sub)))) . "\n}\n";
    },
    "break" => function($ast) {
        return "throw new \\ste\\BreakException();\n";
    },
    "continue" => function($ast) {
        return "throw new \\ste\\ContinueException();\n";
    },
    "block" => function($ast) {
        if(empty($ast->params["name"])) {
            throw new ParseCompileError("Transcompile Error: name missing in <ste:block>.", $ast->tpl, $ast->offset);
        }

        $blknamevar = "blockname_" . str_replace(".", "_", uniqid("", true));

        list($val, $code) = _transcompile($ast->params["name"], true);
        $code .= "\$${blknamevar} = " . $val . ";\n";

        $tmpblk = uniqid("", true);
        $code .= "\$ste->blocks['$tmpblk'] = array_pop(\$outputstack);\n\$ste->blockorder[] = '$tmpblk';\n\$outputstack = array('');\n\$outputstack_i = 0;\n";

        $code .= _transcompile($ast->sub);

        $code .= "\$ste->blocks[\$${blknamevar}] = array_pop(\$outputstack);\n";
        $code .= "if(array_search(\$${blknamevar}, \$ste->blockorder) === false)\n\t\$ste->blockorder[] = \$${blknamevar};\n\$outputstack = array('');\n\$outputstack_i = 0;\n";

        return $code;
    },
    "load" => function($ast) {
        if(empty($ast->params["name"])) {
            throw new ParseCompileError("Transcompile Error: name missing in <ste:load>.", $ast->tpl, $ast->offset);
        }

        list($val, $code) = _transcompile($ast->params["name"], true);
        $code .= "\$outputstack[\$outputstack_i] .= \$ste->load(" . $val . ");\n";
        return $code;
    },
    "mktag" => function($ast) {
        $code = "";

        if(empty($ast->params["name"])) {
            throw new ParseCompileError("Transcompile Error: name missing in <ste:mktag>.", $ast->tpl, $ast->offset);
        }

        $fxbody = "\$outputstack = array(''); \$outputstack_i = 0;\$ste->vars['_tag_parameters'] = \$params;\n";

        list($tagname, $tagname_pre) = _transcompile($ast->params["name"], true);

        $usemandatory = "";
        if(!empty($ast->params["mandatory"])) {
            $usemandatory = " use (\$mandatory_params)";
            $code .= "\$outputstack[] = '';\n\$outputstack_i++;\n";
            $code .= _transcompile($ast->params["mandatory"]);
            $code .= "\$outputstack_i--;\n\$mandatory_params = explode('|', array_pop(\$outputstack));\n";

            $fxbody .= "foreach(\$mandatory_params as \$mp)\n{\n\tif(!isset(\$params[\$mp]))\n\t\tthrow new \\ste\\RuntimeError(\"\$mp missing in <ste:\" . $tagname . \">.\");\n}";
        }

        $fxbody .= _transcompile($ast->sub);
        $fxbody .= "return array_pop(\$outputstack);";

        $code .= "\$tag_fx = function(\$ste, \$params, \$sub)" . $usemandatory . "\n{\n" . indent_code($fxbody) . "\n};\n";
        $code .= $tagname_pre;
        $code .= "\$ste->register_tag($tagname, \$tag_fx);\n";

        return $code;
    },
    "tagcontent" => function($ast) {
        return "\$outputstack[\$outputstack_i] .= \$sub(\$ste);";
    },
    "set" => function($ast) {
        if(empty($ast->params["var"])) {
            throw new ParseCompileError("Transcompile Error: var missing in <ste:set>.", $ast->tpl, $ast->offset);
        }

        $code = "\$outputstack[] = '';\n\$outputstack_i++;\n";
        $code .= _transcompile($ast->sub);
        $code .= "\$outputstack_i--;\n";

        list($val, $pre) = _transcompile($ast->params["var"], true);
        $code .= $pre;
        $code .= "\$ste->set_var_by_name(" . $val . ", array_pop(\$outputstack));\n";

        return $code;
    },
    "get" => function($ast) {
        if(empty($ast->params["var"])) {
            throw new ParseCompileError("Transcompile Error: var missing in <ste:get>.", $ast->tpl, $ast->offset);
        }

        list($val, $pre) = _transcompile($ast->params["var"],  true);
        $code .= $pre;
        return "\$outputstack[\$outputstack_i] .= \$ste->get_var_by_name(" . $val . ");";
    },
    "calc" => function($ast) {
        $code = "\$outputstack[] = '';\n\$outputstack_i++;\n";
        $code .= _transcompile($ast->sub);
        $code .= "\$outputstack_i--;\n\$outputstack[\$outputstack_i] .= \$ste->calc(array_pop(\$outputstack));\n";

        return $code;
    }
);

function escape_text($text) {
    return addcslashes($text, "\r\n\t\$\0..\x1f\\\"\x7f..\xff");
}

function _transcompile($ast, $avoid_outputstack = false) { /* The real transcompile function, does not add boilerplate code. */
    $code = "";
    global $ste_builtins;

    $text_and_var_buffer = array();

    foreach($ast as $node) {
        if($node instanceof TextNode) {
            $text_and_var_buffer[] = '"' . escape_text($node->text) . '"';
        } else if($node instanceof VariableNode) {
            $text_and_var_buffer[] = $node->transcompile();
        } else if($node instanceof TagNode) {
            if(!empty($text_and_var_buffer)) {
                $code .= "\$outputstack[\$outputstack_i] .= " . implode (" . ", $text_and_var_buffer) . ";\n";
                $text_and_var_buffer = array();
            }
            if(isset($ste_builtins[$node->name])) {
                $code .= $ste_builtins[$node->name]($node);
            } else {
                $paramarray = "parameters_" . str_replace(".", "_", uniqid("", true));
                $code .= "\$$paramarray = array();\n";

                foreach($node->params as $pname => $pcontent) {
                    list($pval, $pre) = _transcompile($pcontent, true);
                    $code .= $pre . "\$${paramarray}['" . escape_text($pname) . "'] = " . $pval . ";\n";
                }

                $code .= "\$outputstack[\$outputstack_i] .= \$ste->call_tag('" . escape_text($node->name) . "', \$${paramarray}, ";
                $code .= empty($node->sub) ? "function(\$ste) { return ''; }" : transcompile($node->sub);
                $code .= ");\n";
            }
        }
    }

    if($avoid_outputstack && ($code == "")) {
        return array(implode (" . ", $text_and_var_buffer), "");
    }

    if(!empty($text_and_var_buffer)) {
        $code .= "\$outputstack[\$outputstack_i] .= ". implode (" . ", $text_and_var_buffer) . ";\n";
    }

    if($avoid_outputstack) {
        $tmpvar = "tmp_" . str_replace(".", "_", uniqid("",true));
        $code = "\$outputstack[] = '';\n\$outputstack_i++;" . $code;
        $code .= "\$$tmpvar = array_pop(\$outputstack);\n\$outputstack_i--;\n";
        return array("\$$tmpvar", $code);
    }

    return $code;
}

$ste_transc_boilerplate = "\$outputstack = array('');\n\$outputstack_i = 0;\n";

/*
 * Function: transcompile
 * Transcompiles an abstract syntax tree to PHP.
 * You only need this function, if you want to manually transcompile a template.
 *
 * Parameters:
 *  $ast - The abstract syntax tree to transcompile.
 *
 * Returns:
 *  PHP code. The PHP code is an anonymous function expecting a <STECore> instance as its parameter and returns a string (everything that was not pached into a section).
 */
function transcompile($ast) { /* Transcompile and add some boilerplate code. */
    global $ste_transc_boilerplate;
    return "function(\$ste)\n{\n" . indent_code($ste_transc_boilerplate . _transcompile($ast) . "return array_pop(\$outputstack);") . "\n}";
}

/*
 * Constants: Template modes
 *
 * MODE_SOURCE - The Templates source
 * MODE_TRANSCOMPILED - The transcompiled template
 */
const MODE_SOURCE        = 0;
const MODE_TRANSCOMPILED = 1;

abstract class StorageAccessFailure extends \Exception { }

/*
 * Class: CantLoadTemplate
 * An exception that a <StorageAccess> implementation can throw, if it is unable to load a template.
 */
class CantLoadTemplate extends StorageAccessFailure { }

/*
 * Class: CantSaveTemplate
 * An exception that a <StorageAccess> implementation can throw, if it is unable to save a template.
 */
class CantSaveTemplate extends StorageAccessFailure { }

/*
 * Class: StorageAccess
 * An interface.
 * A StorageAccess implementation is used to access the templates from any storage.
 * This means, that you are not limited to store the Templates inside directories, you can also use a database or something else.
 */
interface StorageAccess {
    /*
     * Function: load
     * Loading a template.
     *
     * Parameters:
     *  $tpl - The name of the template.
     *  &$mode - Which mode is preferred? One of the <Template modes>.
     *           If <MODE_SOURCE>, the raw sourcecode is expected, if <MODE_TRANSCOMPILED> the transcompiled template *as a callable function* (expecting an <STECore> instance as first parameter) is expected.
     *           If the transcompiled version is not available or older than the source, you can set this parameter to <MODE_SOURCE> and return the source.
     *
     * Throws:
     *  A <CantLoadTemplate> exception if the template could not be loaded.
     *
     * Returns:
     *  Either the sourcecode or a callable function (first, and only parameter: an <STECore> instance).
     */
    public function load($tpl, &$mode);

    /*
     * Function: save
     * Saves a template.
     *
     * Throws:
     *  A <CantSaveTemplate> exception if the template could not be saved.
     *
     * Parameters:
     *  $tpl -The name of the template.
     *  $data - The data to be saved.
     *  $mode - A <Template mode> constant.
     */
    public function save($tpl, $data, $mode);
}

/*
 * Class: FilesystemStorageAccess
 * The default <StorageAccess> implementation for loading / saving templates into a directory structure.
 */
class FilesystemStorageAccess implements StorageAccess {
    protected $sourcedir;
    protected $transcompileddir;

    /*
     * Constructor: __construct
     *
     * Parameters:
     *  $src - The directory with the sources (Writing permissions are not mandatory, because STE does not save template sources).
     *  $transc - The directory with the transcompiled templates (the PHP instance / the HTTP Server needs writing permissions to this directory).
     */
    public function __construct($src, $transc) {
        $this->sourcedir        = $src;
        $this->transcompileddir = $transc;
    }

    public function load($tpl, &$mode) {
        $src_fn    = $this->sourcedir        . "/" . $tpl;
        $transc_fn = $this->transcompileddir . "/" . $tpl . ".php";

        if($mode == MODE_SOURCE) {
            $content = @file_get_contents($src_fn);
            if($content === false) {
                throw new CantLoadTemplate("Template not found.");
            }
            return $content;
        }

        $src_stat    = @stat($src_fn);
        $transc_stat = @stat($transc_fn);

        if(($src_stat === false) and ($transc_stat === false)) {
            throw new CantLoadTemplate("Template not found.");
        } else if($transc_stat === false) {
            $mode = MODE_SOURCE;
            return file_get_contents($src_fn);
        } else if($src_stat === false) {
            include($transc_fn);
            return $transcompile_fx;
        } else {
            if($src_stat["mtime"] > $transc_stat["mtime"]) {
                $mode = MODE_SOURCE;
                return file_get_contents($src_fn);
            } else {
                include($transc_fn);
                return $transcompile_fx;
            }
        }
    }

    public function save($tpl, $data, $mode) {
        $fn = (($mode == MODE_SOURCE) ? $this->sourcedir : $this->transcompileddir) . "/" . $tpl . (($mode == MODE_TRANSCOMPILED) ? ".php" : "");
        @mkdir(dirname($fn), 0777, true);
        if(file_put_contents($fn, "<?php \$transcompile_fx = $data; ?>") === false) {
            throw new CantSaveTemplate("Unable to save template.");
        }
    }
}

class BreakException    extends \Exception { }
class ContinueException extends \Exception { }

/*
 * Class: STECore
 * The Core of STE
 */
class STECore {
    private $tags;
    private $storage_access;
    private $cur_tpl_dir;

    /*
     * Variables: Public variables
     *
     * $blocks - Associative array of blocks (see the language definition).
     * $blockorder - The order of the blocks (an array)
     * $vars - Associative array of all template variables. Use this to pass data to your templates.
     * $mute_runtime_errors - If true (default) a <RuntimeError> exception will result in no output from the tag, if false a error message will be written to output.
     * $fatal_error_on_missing_tag - If true, STE will throw a <FatalRuntimeError> if a tag was called that was not registered, otherwise (default) a regular <RuntimeError> will be thrown and automatically handled by STE (see <$mute_runtime_errors>).
     */
    public $blocks;
    public $blockorder;
    public $vars;
    public $mute_runtime_errors = true;
    public $fatal_error_on_missing_tag = false;

    /*
     * Constructor: __construct
     *
     * Parameters:
     *  $storage_access - An Instance of a <StorageAccess> implementation.
     */
    public function __construct($storage_access) {
        $this->storage_access = $storage_access;
        $this->cur_tpl_dir = "/";
        STEStandardLibrary::_register_lib($this);
        $this->vars = array();
        $this->blockorder = array();
        $this->blocks = array();
    }

    /*
     * Function: register_tag
     * Register a custom tag.
     *
     * Parameters:
     *  $name - The name of the tag.
     *  $callback - A callable function (This must take three parameters: The <STECore> instance, an associative array of parameters, and a function representing the tags content(This expects the <STECore> instance as its only parameter and returns its text result, i.e to get the text, you neeed to call this function with the <STECore> instance as a parameter)).
     *
     * Throws:
     *  An Exception if the tag could not be registered (if $callback is not callable or if $name is empty)
     */
    public function register_tag($name, $callback) {
        if(!is_callable($callback)) {
            throw new \Exception("Can not register tag \"$name\", not callable.");
        }
        if(empty($name)) {
            throw new \Exception("Can not register tag, empty name.");
        }
        $this->tags[$name] = $callback;
    }

    /*
     * Function: call_tag
     * Calling a custom tag (builtin ones can not be called)
     *
     * Parameters:
     *  $name - The Tag's name
     *  $params - Associative array of parameters
     *  $sub - A callable function (expecting an <STECore> instance as it's parameter) that represents the tag's content.
     *
     * Throws:
     *  Might throw a <FatalRuntimeError> (see <$fatal_error_on_missing_tag>.
     *
     * Returns:
     *  The output of the tag or, if a <RuntimeError> was thrown, the appropiate result (see <$mute_runtime_errors>).
     */
    public function call_tag($name, $params, $sub) {
        try {
            if(!isset($this->tags[$name])) {
                if($this->fatal_error_on_missing_tag) {
                    throw new FatalRuntimeError("Can not call tag \"$name\": Does not exist.");
                } else {
                    throw new RuntimeError("Can not call tag \"$name\": Does not exist.");
                }
            }
            return call_user_func($this->tags[$name], $this, $params, $sub);
        } catch(RuntimeError $e) {
            if(!$this->mute_runtime_errors) {
                return "RuntimeError occurred on tag '$name': " . $e->getMessage();
            }
        }
    }

    public function calc($expression) {
        return calc_rpn(shunting_yard($expression));
    }

    /*
     * Function: exectemplate
     * Executes a template and returns the result. The huge difference to <load> is that this function will also output all blocks.
     *
     * Parameters:
     *  $tpl - The name of the template to execute.
     *
     * Throws:
     *  * A <CantLoadTemplate> exception if the template could not be loaded.
     *  * A <ParseCompileError> if the template could not be parsed or transcompiled.
     *  * A <FatalRuntimeError> if a tag threw it or if a tag was not found and <$fatal_error_on_missing_tag> is true.
     *  * Might also throw different exceptions, if a external tag threw it (but they should use <RuntimeError> or <FatalRuntimeError> to make it possible for STE to handle them correctly).
     *
     * Returns:
     *  The output of the template.
     */
    public function exectemplate($tpl) {
        $output = "";
        $lastblock = $this->load($tpl);

        foreach($this->blockorder as $blockname) {
            $output .= $this->blocks[$blockname];
        }

        return $output . $lastblock;
    }

    /*
     * Function: get_var_reference
     * Get a reference to a template variable using a variable name.
     * This can be used,if your custom tag takes a variable name as a parameter.
     *
     * Parameters:
     *  $name - The variables name.
     *  $create_if_not_exist - Should the variable be created, if it does not exist? Otherwise NULL will be returned, if the variable does not exist.
     *
     * Throws:
     *  <RuntimeError> if the variable name can not be parsed (e.g. unbalanced brackets).
     *
     * Returns:
     *  A Reference to the variable.
     */

    public function &get_var_reference($name, $create_if_not_exist) {
        $ref = &$this->_get_var_reference($this->vars, $name, $create_if_not_exist);
        return $ref;
    }

    private function &_get_var_reference(&$from, $name, $create_if_not_exist) {
        $nullref = NULL;

        $bracket_open = strpos($name, "[");
        if($bracket_open === false) {
            if(isset($from[$name]) or $create_if_not_exist) {
                $ref = &$from[$name];
                return $ref;
            } else {
                return $nullref;
            }
        } else {
            $old_varname = $varname;
            $bracket_close = strpos($name, "]", $bracket_open);
            if($bracket_close === false) {
                throw new RuntimeError("Invalid varname \"$varname\". Missing closing \"]\".");
            }
            $varname = substr($name, 0, $bracket_open);
            $name    = substr($name, $bracket_open + 1, $bracket_close - $bracket_open - 1) . substr($name, $bracket_close + 1);
            if(!is_array($from[$varname])) {
                if($create_if_not_exist) {
                    $from[$varname] = array();
                } else {
                    return $nullref;
                }
            }
            try {
                $ref = &$this->_get_var_reference($from[$varname], $name, $create_if_not_exist);
                return $ref;
            } catch(Exception $e) {
                throw new RuntimeError("Invalid varname \"$old_varname\". Missing closing \"]\".");
            }
        }
    }

    /*
     * Function: set_var_by_name
     * Set a template variable by its name.
     * This can be used,if your custom tag takes a variable name as a parameter.
     *
     * Parameters:
     *  $name - The variables name.
     *  $val - The new value.
     *
     * Throws:
     *  <RuntimeError> if the variable name can not be parsed (e.g. unbalanced brackets).
     */
    public function set_var_by_name($name, $val) {
        $ref = &$this->_get_var_reference($this->vars, $name, true);
        $ref = $val;
    }

    /*
     * Function: get_var_by_name
     * Get a template variable by its name.
     * This can be used,if your custom tag takes a variable name as a parameter.
     *
     * Parameters:
     *  $name - The variables name.
     *
     * Throws:
     *  <RuntimeError> if the variable name can not be parsed (e.g. unbalanced brackets).
     *
     * Returns:
     *  The variables value.
     */
    public function get_var_by_name($name) {
        $ref = $this->_get_var_reference($this->vars, $name, false);
        return $ref === NULL ? "" : $ref;
    }

    /*
     * Function: load
     * Load a template and return its result (blocks not included, use <exectemplate> for this).
     *
     * Parameters:
     *  $tpl - The name of the template to be loaded.
     *  $quiet - If true, do not output anything and do not modify the blocks. This can be useful to load custom tags that are programmed in the STE Template Language. Default: false.
     *
     * Throws:
     *  * A <CantLoadTemplate> exception if the template could not be loaded.
     *  * A <ParseCompileError> if the template could not be parsed or transcompiled.
     *  * A <FatalRuntimeError> if a tag threw it or if a tag was not found and <$fatal_error_on_missing_tag> is true.
     *  * Might also throw different exceptions, if a external tag threw it (but they should use <RuntimeError> or <FatalRuntimeError> to make it possible for STE to handle them correctly).
     *
     * Returns:
     *  The result of the template (if $quiet == false).
     */
    public function load($tpl, $quiet = false) {
        $tpldir_b4 = $this->cur_tpl_dir;

        /* Resolve ".", ".." and protect from possible LFI */
        $tpl = str_replace("\\", "/", $tpl);
        if($tpl[0] != "/") {
            $tpl = $this->cur_tpl_dir . "/" . $tpl;
        }
        $pathex = array_filter(explode("/", $tpl), function($s) { return ($s != ".") and (!empty($s)); });
        $pathex = array_merge($pathex);
        while(($i = array_search("..", $pathex)) !== false) {
            if($i == 0) {
                $pathex = array_slice($pathex, 1);
            } else {
                $pathex = array_merge(array_slice($pathex, 0, $i), array_slice($pathex, $i + 2));
            }
        }
        $tpl = implode("/", $pathex);
        $this->cur_tpl_dir = dirname($tpl);

        if($quiet) {
            $blocks_back     = clone $this->blocks;
            $blockorder_back = clone $this->blockorder;
        }

        $mode = MODE_TRANSCOMPILED;
        $content = $this->storage_access->load($tpl, $mode);
        if($mode == MODE_SOURCE) {
            try {
                $ast     = Parser::parse($content, $tpl);
                $transc  = transcompile($ast);
            } catch(ParseCompileError $e) {
                $e->rewrite($content);
                throw $e;
            }
            $this->storage_access->save($tpl, $transc, MODE_TRANSCOMPILED);
            eval("\$content = $transc;");
        }

        $output = $content($this);

        $this->cur_tpl_dir = $tpldir_b4;

        if($quiet) {
            $this->blocks     = $blocks_back;
            $this->blockorder = $blockorder_back;
        } else {
            return $output;
        }
    }

    /*
     * Function: evalbool
     * Test, if a text represents false (an empty / only whitespace text) or true (everything else).
     *
     * Parameters:
     *  $txt - The text to test.
     *
     * Returns:
     *  true/false.
     */
    public function evalbool($txt) {
        return trim((string) $txt) != "";
    }
}

class STEStandardLibrary {
    static public function _register_lib($ste) {
        foreach(get_class_methods(__CLASS__) as $method) {
            if($method[0] != "_") {
                $ste->register_tag($method, array(__CLASS__, $method));
            }
        }
    }

    static public function escape($ste, $params, $sub) {
        if(isset($params["lines"]) && $ste->evalbool($params["lines"])) {
            return nl2br(htmlspecialchars(str_replace("\r\n", "\n", $sub($ste))));
        } else {
            return htmlspecialchars($sub($ste));
        }
    }

    static public function strlen($ste, $params, $sub) {
        return strlen($sub($ste));
    }

    static public function arraylen($ste, $params, $sub) {
        if(empty($params["array"])) {
            throw new RuntimeError("Missing array parameter in <ste:arraylen>.");
        }
        $a = $ste->get_var_by_name($params["array"], false);
        return (is_array($a)) ? count($a) : "";
    }

    static public function inc($ste, $params, $sub) {
        if(empty($params["var"])) {
            throw new RuntimeError("Missing var parameter in <ste:inc>.");
        }
        $ref = &$ste->get_var_reference($params["var"], true);
        $ref++;
    }

    static public function dec($ste, $params, $sub) {
        if(empty($params["var"])) {
            throw new RuntimeError("Missing var parameter in <ste:dec>.");
        }
        $ref = &$ste->get_var_reference($params["var"], true);
        $ref--;
    }

    static public function date($ste, $params, $sub) {
        return @strftime($sub($ste), empty($params["timestamp"]) ? @time() : (int) $params["timestamp"]);
    }

    static public function in_array($ste, $params, $sub) {
        if(empty($params["array"])) {
            throw new RuntimeError("Missing array parameter in <ste:in_array>.");
        }
        $ar = &$ste->get_var_reference($params["array"], false);
        if(!is_array($ar)) {
            return "";
        }
        return in_array($sub($ste), $ar) ? "y" : "";
    }

    static public function join($ste, $params, $sub) {
        if(empty($params["array"])) {
            throw new RuntimeError("Missing array parameter in <ste:join>.");
        }
        return implode($sub($ste), $ste->get_var_by_name($params["array"]));
    }

    static public function split($ste, $params, $sub) {
        if(empty($params["array"])) {
            throw new RuntimeError("Missing array parameter in <ste:split>.");
        }
        if(empty($params["delim"])) {
            throw new RuntimeError("Missing delim parameter in <ste:split>.");
        }
        $ste->set_var_by_name($params["array"], explode($params["delim"], $sub($ste)));
    }

    static public function array_add($ste, $params, $sub) {
        if(empty($params["array"])) {
            throw new RuntimeError("Missing array parameter in <ste:array_add>.");
        }

        $ar = &$ste->get_var_reference($params["array"], true);
        if(empty($params["key"])) {
            $ar[] = $sub($ste);
        } else {
            $ar[$params["key"]] = $sub($ste);
        }
    }

    static public function array_filter($ste, $params, $sub)
    {
        if(empty($params["array"])) {
            throw new RuntimeError("Missing array parameter in <ste:array_filter>.");
        }

        $ar = $ste->get_var_by_name($params["array"]);
        if(!is_array($ar)) {
            throw new RuntimeError("Variable at 'array' is not an array.");
        }

        $keys = array_keys($ar);

        if(!empty($params["keep_by_keys"])) {
            $keep_by_keys = &$ste->get_var_reference($params["keep_by_keys"], false);
            if(!is_array($keep_by_keys)) {
                throw new RuntimeError("Variable at 'keep_by_keys' is not an array.");
            }
            $delkeys = array_filter($keys, function($k) use ($keep_by_keys) { return !in_array($k, $keep_by_keys); });
            foreach($delkeys as $dk) {
                unset($ar[$dk]);
            }
            $keys = array_keys($ar);
        }
        if(!empty($params["keep_by_values"])) {
            $keep_by_values = &$ste->get_var_reference($params["keep_by_values"], false);
            if(!is_array($keep_by_values)) {
                throw new RuntimeError("Variable at 'keep_by_values' is not an array.");
            }
            $ar = array_filter($ar, function($v) use ($keep_by_values) { return in_array($v, $keep_by_values); });
            $keys = array_keys($ar);
        }
        if(!empty($params["delete_by_keys"])) {
            $delete_by_keys = &$ste->get_var_reference($params["delete_by_keys"], false);
            if(!is_array($delete_by_keys)) {
                throw new RuntimeError("Variable at 'delete_by_keys' is not an array.");
            }
            $delkeys = array_filter($keys, function($k) use ($delete_by_keys) { return in_array($k, $delete_by_keys); });
            foreach($delkeys as $dk) {
                unset($ar[$dk]);
            }
            $keys = array_keys($ar);
        }
        if(!empty($params["delete_by_values"])) {
            $delete_by_values = &$ste->get_var_reference($params["delete_by_values"], false);
            if(!is_array($delete_by_values)) {
                throw new RuntimeError("Variable at 'delete_by_values' is not an array.");
            }
            $ar = array_filter($ar, function($v) use ($delete_by_values) { return !in_array($v, $delete_by_values); });
            $keys = array_keys($ar);
        }

        $ste->set_var_by_name($params["array"], $ar);
    }
}
