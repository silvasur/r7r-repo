<?php

/*
 * File: stupid_template_engine.php
 * The implementation of the Stupid Template Engine.
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

abstract class ASTNode
{
	public $tpl;
	public $offset;
	public function __construct($tpl, $off)
	{
		$this->tpl    = $tpl;
		$this->offset = $off;
	}
}

class TextNode extends ASTNode
{
	public $text;
}

class TagNode extends ASTNode
{
	public $name;
	public $params;
	public $sub;
}

class VariableNode extends ASTNode
{
	public $name;
	public $arrayfields;
	public function transcompile()
	{
		$varaccess = '@$ste->vars[' . (is_numeric($this->name) ? $this->name : '\'' . escape_text($this->name) . '\''). ']';
		foreach($this->arrayfields as $af)
		{
			if((count($af) == 1) and ($af[0] instanceof TextNode) and is_numeric($af->text))
				$varaccess .= '[' . $af->text . ']';
			else
				$varaccess .= '[' . implode(".",
					array_map(
						function($node)
						{
							if($node instanceof TextNode)
								return "\"" . escape_text($node->text) . "\"";
							else if($node instanceof VariableNode)
								return $node->transcompile();
						}, $af
					)
				). ']';
		}
		return $varaccess;
	}
}

class ParseCompileError extends \Exception
{
	public $msg;
	public $tpl;
	public $off;
	
	public function __construct($msg, $tpl, $offset, $code = 0, $previous = NULL)
	{
		$this->msg = $msg;
		$this->tpl = $tpl;
		$this->off = $offset;
		$this->message = "$msg (Template $tpl, Offset $offset)";
	}
	
	public function rewrite($code)
	{
		$line = substr_count(str_replace("\r\n", "\n", $code), "\n", 0, $this->off + 1) + 1;
		$this->message = "{$this->msg} (Template $tpl, Line $line)";
		$this->is_rewritten = True;
	}
}

/* $text must start after the first opening bracket */
function find_closing_bracket($text, $opening, $closing)
{
	$counter = 1;
	$len = strlen($text);
	for($i = 0; $i < $len; ++$i)
	{
		switch($text[$i])
		{
			case $opening:
				++$counter;
				break;
			case $closing:
				--$counter;
				break;
		}
		if($counter == 0)
			break;
	}
	
	if($counter > 0)
		throw new \Exception("Missing closing \"$closing\". Stop.");
	
	return $i;
}

function instance_in_array($classname, $a)
{
	foreach($a as $v)
	{
		if($v instanceof $classname)
			return True;
	}
	return False;
}

function unescape_text($text)
{
	return stripcslashes($text);
}

function tokenize_text($text, $tpl, $off)
{
	$tokens = array();
	/* Find next non-escaped $-char */
	if(preg_match("/(?:(?<!\\\\)\\$)/s", $text, $match, PREG_OFFSET_CAPTURE) == 0)
	{
		$node = new TextNode($tpl, $off);
		$node->text = preg_replace("/^\\n\\s*/s", "", unescape_text($text));
		return (strlen($node->text) == 0) ? array() : array($node);
	}
	
	if($match[0][1] > 0)
	{
		$node = new TextNode($tpl, $off);
		$node->text = unescape_text(substr($text, 0, $match[0][1]));
		$tokens[] = $node;
	}
	
	if($text[$match[0][1] + 1] == "{")
	{
		try
		{
			$varend = find_closing_bracket(substr($text, $match[0][1] + 2), "{", "}") + $match[0][1] + 2;
		}
		catch(\Exception $e)
		{
			throw new ParseCompileError("Parse Error: Missing closing '}'", $tpl, $off + $match[0][1] + 1);
		}
		return array_merge(
			$tokens,
			tokenize_text("\$" . substr($text, $match[0][1] + 2, ($varend - 1) - ($match[0][1] + 1)), $tpl, $off + $match[0][1] + 2),
			tokenize_text(substr($text, $varend + 1), $tpl, $off + $varend + 1)
		);
	}
	
	$text = substr($text, $match[0][1] + 1);
	$off += $match[0][1] + 1;
	if(preg_match("/^[a-zA-Z0-9_]+/s", $text, $match, PREG_OFFSET_CAPTURE) == 0)
	{
		$nexttokens = tokenize_text($text, $tpl, $off);
		if($nexttokens[0] instanceof TextNode)
			$nexttokens[0]->text = "\$" . $nexttokens[0]->text;
		else
		{
			$node = new TextNode($tpl, $off);
			$node->text = "\$";
			$tokens[] = $node;
		}
		return array_merge($tokens, $nexttokens);
	}
	
	$node = new VariableNode($tpl, $off + $match[0][1]);
	$node->name = $match[0][0];
	$node->arrayfields = array();
	
	$text = substr($text, $match[0][1] + strlen($match[0][0]));
	$off += $match[0][1] + strlen($match[0][0]);
	while(@$text[0] == "[")
	{
		$text = substr($text, 1);
		$off += 1;
		try
		{
			$fieldend = find_closing_bracket($text, "[", "]");
		}
		catch(\Exception $e)
		{
			throw new ParseCompileError("Parse Error: Missing closing ']'", $tpl, $off - 1);
		}
		$node->arrayfields[] = tokenize_text(substr($text, 0, $fieldend), $tpl, $off);
		$text = substr($text, $fieldend + 1);
		$off += $fieldend + 1;
	}
	
	$tokens[] = $node;
	
	return strlen($text) > 0 ? array_merge($tokens, tokenize_text($text, $tpl, $off)) : $tokens;
}

function mk_ast($code, $tpl, $err_off)
{
	$ast = array();
	
	if(preg_match("/\\<\\s*ste:([a-zA-Z0-9_]*)/s", $code, $matches, PREG_OFFSET_CAPTURE) == 0)
		return tokenize_text($code, $tpl, $err_off);
	
	$ast = tokenize_text(substr($code, 0, $matches[0][1]), $tpl, $err_off);
	$tag = new TagNode($tpl, $err_off + $matches[0][1]);
	$tag->name = $matches[1][0];
	
	$code = substr($code, $matches[0][1] + strlen($matches[0][0]));
	$err_off += $matches[0][1] + strlen($matches[0][0]);
	
	$tag->params = array();
	
	while(preg_match("/^\\s+([a-zA-Z0-9_]+)=((?:\"(?:.*?)(?<!\\\\)\")|(?:'(?:.*?)(?<!\\\\)'))/s", $code, $matches, PREG_OFFSET_CAPTURE) > 0)
	{
		$paramval = substr($code, $matches[2][1] + 1, strlen($matches[2][0]) - 2);
		$paramval = str_replace("\\\"", "\"", $paramval);
		$paramval = str_replace("\\'", "'", $paramval);
		$tag->params[$matches[1][0]] = tokenize_text($paramval, $tpl, $err_off + $matches[2][1] + 1);
		$code = substr($code, strlen($matches[0][0]));
		$err_off += strlen($matches[0][0]);
	}
	
	if(preg_match("/^\\s*([\\/]?)\\s*\\>/s", $code, $matches) == 0)
		throw new ParseCompileError("Parse Error: Missing closing '>' in \"" . $tag->name . "\"-Tag.", $tpl, $tag->offset);
	
	$code = substr($code, strlen($matches[0]));
	$err_off += strlen($matches[0]);
	
	$tag->sub = array();
	
	if($matches[1][0] != "/")
	{
		$off = 0;
		$last_tag_start = 0;
		$tagstack = array(array($tag->name, $tag->offset));
		while(preg_match("/\\<((?:\\s*)|(?:\\s*\\/\\s*))ste:([a-zA-Z0-9_]*)(?:\\s+(?:[a-zA-Z0-9_]+)=(?:(?:\"(?:.*?)(?<!\\\\)\")|(?:'(?:.*?)(?<!\\\\)')))*((?:\\s*)|(?:\\s*\\/\\s*))\\>/s", $code, $matches, PREG_OFFSET_CAPTURE, $off) > 0) /* RegEx from hell! Matches all  <ste:> Tags. Opening, closing and self-closing ones. */
		{
			if(trim($matches[3][0]) != "/")
			{
				$closingtag = trim($matches[1][0]);
				if($closingtag[0] == "/")
				{
					list($matching_opentag, $mo_off) = array_pop($tagstack);
					if($matching_opentag != $matches[2][0])
						throw new ParseCompileError("Parse Error: Missing closing \"ste:$matching_opentag\"-Tag.", $tpl, $mo_off + $err_off);
				}
				else
					$tagstack[] = array($matches[2][0], $matches[0][1]);
			}
			$last_tag_start = $matches[0][1];
			$off = $last_tag_start + strlen($matches[0][0]);
			if(empty($tagstack))
				break;
		}
		
		if((!empty($tagstack)) or ($tag->name != $matches[2][0]))
			throw new ParseCompileError("Parse Error: Missing closing \"ste:" . $tag->name . "\"-Tag.", $tpl, $tag->offset);
		
		if($tag->name == "rawtext")
		{
			$tag = new TextNode($tpl, $err_off);
			$tag->text = substr($code, 0, $last_tag_start);
		}
		else if($tag->name == "comment")
			$tag = NULL; /* All this work to remove a comment ... */
		else
			$tag->sub = mk_ast(substr($code, 0, $last_tag_start), $tpl, $err_off);
		$code = substr($code, $off);
		$err_off += $off;
	}
	
	if($tag !== NULL)
		$ast[] = $tag;
	return array_merge($ast, strlen($code) > 0 ? mk_ast($code, $tpl, $err_off) : array());
}

/*
 * Function: precompile
 * Precompiling STE T/PL templates.
 * You only need this function, if you want to manually transcompile a template.
 * 
 * Parameters:
 * 	$code - The input code
 * 
 * Returns:
 * 	The precompiled code.
 */
function precompile($code)
{
	$code = preg_replace( /* Transform short form of comparison (~{a|op|b}) to long form */
		"/(?:(?<!\\\\)~)(?:(?<!\\\\)\\{)(.*?)(?:(?<!\\\\)\\|)(.*?)(?:(?<!\\\\)\\|)(.*?)(?:(?<!\\\\)\\})/s",
		"<ste:cmp text_a=\"\$1\" op=\"\$2\" text_b=\"\$3\" />",
		$code
	);
	$code = preg_replace( /* Transform short form of if-clause (?{cond|then|else}) to long form */
		"/(?:(?<!\\\\)\\?)(?:(?<!\\\\)\\{)(.*?)(?:(?<!\\\\)\\|)(.*?)(?:(?<!\\\\)\\|)(.*?)(?:(?<!\\\\)\\})/s",
		"<ste:if>\$1<ste:then>\$2</ste:then><ste:else>\$3</ste:else></ste:if>",
		$code
	);
	/* Unescape \? \~ \{ \} \| */
	$code = preg_replace("/(?:(?<!\\\\)\\\\\\?)/s", "?", $code);
	$code = preg_replace("/(?:(?<!\\\\)\\\\~)/s",   "~", $code);
	$code = preg_replace("/(?:(?<!\\\\)\\\\\\{)/s", "{", $code);
	$code = preg_replace("/(?:(?<!\\\\)\\\\\\})/s", "}", $code);
	$code = preg_replace("/(?:(?<!\\\\)\\\\\\|)/s", "|", $code);
	
	return $code;
}

/*
 * Function: parse
 * Parsing a STE T/PL template.
 * You only need this function, if you want to manually transcompile a template.
 * 
 * Parameters:
 * 	$code - The STE T/PL code.
 * 	$tpl  - The name of the template.
 * 
 * Returns:
 * 	An abstract syntax tree, which can be used with <transcompile>.
 */
function parse($code, $tpl)
{
	return mk_ast($code, $tpl, 0);
}

function indent_code($code)
{
	return implode(
		"\n",
		array_map(
			function($line) { return "\t$line"; },
			explode("\n", $code)
		)
	);
}

/* We could also just eval() the $infix_math code, but this is much cooler :-D (Parser inception) */
function shunting_yard($infix_math)
{
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
	$tokens_raw = array_filter(array_map('trim', $tokens[0]), function($x) { return ($x === "0") or (!empty($x)); });
	$output_queue = array();
	$op_stack     = array();
	
	$lastpriority = NULL;
	/* Make - unary, if neccessary */
	$tokens = array();
	foreach($tokens_raw as $token)
	{
		$priority = isset($operators[$token]) ? $operators[$token][1] : -1;
		if(($token == "-") and (($lastpriority === NULL) or ($lastpriority >= 0)))
		{
			$priority = $operators["_"][1];
			$tokens[] = "_";
		}
		else
			$tokens[] = $token;
		$lastpriority = $priority;
	}
	
	while(!empty($tokens))
	{
		$token = array_shift($tokens);
		if(is_numeric($token))
			$output_queue[] = $token;
		else if($token == "(")
			$op_stack[] = $token;
		else if($token == ")")
		{
			$lbr_found = False;
			while(!empty($op_stack))
			{
				$op = array_pop($op_stack);
				if($op == "(")
				{
					$lbr_found = True;
					break;
				}
				$output_queue[] = $op;
			}
			if(!$lbr_found)
				throw new \Exception("Bracket mismatch.");
		}
		else if(!isset($operators[$token]))
			throw new \Exception("Invalid token ($token): Not a number, bracket or operator. Stop.");
		else
		{
			$priority = $operators[$token][1];
			if($operators[$token][0] == "l")
				while((!empty($op_stack)) and ($priority <= $operators[$op_stack[count($op_stack)-1]][1]))
					$output_queue[] = array_pop($op_stack);
			else
				while((!empty($op_stack)) and ($priority < $operators[$op_stack[count($op_stack)-1]][1]))
					$output_queue[] = array_pop($op_stack);
			$op_stack[] = $token;
		}
	}
	
	while(!empty($op_stack))
	{
		$op = array_pop($op_stack);
		if($op == "(")
			throw new \Exception("Bracket mismatch...");
		$output_queue[] = $op;
	}
	
	return $output_queue;
}

function pop2(&$array)
{
	$rv = array(array_pop($array), array_pop($array));
	if(array_search(NULL, $rv, True) !== False)
		throw new \Exception("Not enough numbers on stack. Invalid formula.");
	return $rv;
}

function calc_rpn($rpn)
{
	$stack = array();
	foreach($rpn as $token)
	{
		switch($token)
		{
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
				if($a === NULL)
					throw new \Exception("Not enough numbers on stack. Invalid formula.");
				$stack[] = -$a;
				break;
			default:
				$stack[] = $token;
				break;
		}
	}
	return array_pop($stack);
}

function loopbody($code)
{
	return "try\n{\n" . indent_code($code) . "\n}\ncatch(\ste\BreakException \$e) { break; }\ncatch(\ste\ContinueException \$e) { continue; }\n";
}

$ste_builtins = array(
	"if" => function($ast)
	{
		$output = "";
		$condition = array();
		$then = NULL;
		$else = NULL;
		
		foreach($ast->sub as $node)
		{
			if(($node instanceof TagNode) and ($node->name == "then"))
				$then = $node->sub;
			else if(($node instanceof TagNode) and ($node->name == "else"))
				$else = $node->sub;
			else
				$condition[] = $node;
		}
		
		if($then === NULL)
			throw new ParseCompileError("Transcompile error: Missing <ste:then> in <ste:if>.", $ast->tpl, $ast->offset);
		
		$output .= "\$outputstack[] = \"\";\n\$outputstack_i++;\n";
		$output .= _transcompile($condition);
		$output .= "\$outputstack_i--;\nif(\$ste->evalbool(array_pop(\$outputstack)))\n{\n";
		$output .= indent_code(_transcompile($then));
		$output .= "\n}\n";
		if($else !== NULL)
		{
			$output .= "else\n{\n";
			$output .= indent_code(_transcompile($else));
			$output .= "\n}\n";
		}
		return $output;
	},
	"cmp" => function($ast)
	{
		$operators = array(
			array('eq', '=='),
			array('neq', '!='),
			array('lt', '<'),
			array('lte', '<='),
			array('gt', '>'),
			array('gte', '>=')
		);
		
		$code = "";
		
		if(isset($ast->params["var_b"]))
			$b = '$ste->get_var_by_name(' . _transcompile($ast->params["var_b"], True) . ')';
		else if(isset($ast->params["text_b"]))
			$b = _transcompile($ast->params["text_b"], True);
		else
			throw new ParseCompileError("Transcompile error: neiter var_b nor text_b set in <ste:cmp>.", $ast->tpl, $ast->offset);
		
		if(isset($ast->params["var_a"]))
			$a = '$ste->get_var_by_name(' . _transcompile($ast->params["var_a"], True) . ')';
		else if(isset($ast->params["text_a"]))
			$a = _transcompile($ast->params["text_a"], True);
		else
			throw new ParseCompileError("Transcompile error: neiter var_a nor text_a set in <ste:cmp>.", $ast->tpl, $ast->offset);
		
		if(!isset($ast->params["op"]))
			throw new ParseCompileError("Transcompile error: op not given in <ste:cmp>.", $ast->tpl, $ast->offset);
		if((count($ast->params["op"]) == 1) and ($ast->params["op"][0] instanceof TextNode))
		{
			/* Operator is known at compile time, this saves *a lot* of output code! */
			$op = trim($ast->params["op"][0]->text);
			$op_php = NULL;
			foreach($operators as $v)
			{
				if($v[0] == $op)
				{
					$op_php = $v[1];
					break;
				}
			}
			if($op_php === NULL)
				throw new ParseCompileError("Transcompile Error: Unknown operator in <ste:cmp>", $ast->tpl, $ast->offset);
			$code .= "\$outputstack[\$outputstack_i] .= (($a) $op_php ($b)) ? 'yes' : '';\n";
		}
		else
		{
			$code .= "switch(trim(" . _transcompile($ast->params["op"], True) . "))\n{\n\t";
			$code .= implode("", array_map(
					function($op) use ($a,$b)
					{
						list($op_stetpl, $op_php) = $op;
						return "case '$op_stetpl':\n\t\$outputstack[\$outputstack_i] .= (($a) $op_php ($b)) ? 'yes' : '';\n\tbreak;\n\t";
					}, $operators
				));
			$code .= "default: throw new \Exception('Runtime Error: Unknown operator in <ste:cmp>.');\n}\n";
		}
		return $code;
	},
	"not" => function($ast)
	{
		$code = "\$outputstack[] = '';\n\$outputstack_i++;\n";
		$code .= _transcompile($ast->sub);
		$code .= "\$outputstack_i--;\n\$outputstack[\$outputstack_i] .= (!\$ste->evalbool(array_pop(\$outputstack))) ? 'yes' : '';\n";
		return $code;
	},
	"even" => function($ast)
	{
		$code = "\$outputstack[] = '';\n\$outputstack_i++;\n";
		$code .= _transcompile($ast->sub);
		$code .= "\$outputstack_i--;\n\$tmp_even = array_pop(\$outputstack);\n\$outputstack[\$outputstack_i] .= (is_numeric(\$tmp_even) and (\$tmp_even % 2 == 0)) ? 'yes' : '';\n";
		return $code;
	},
	"for" => function($ast)
	{
		$code = "";
		$loopname = "forloop_" . str_replace(".", "_", uniqid("",True));
		if(empty($ast->params["start"]))
			throw new ParseCompileError("Transcompile error: Missing 'start' parameter in <ste:for>.", $ast->tpl, $ast->offset);
		$code .= "\$${loopname}_start = " . _transcompile($ast->params["start"], True) . ";\n";
		
		if(empty($ast->params["stop"]))
			throw new ParseCompileError("Transcompile error: Missing 'end' parameter in <ste:for>.", $ast->tpl, $ast->offset);
		$code .= "\$${loopname}_stop = " . _transcompile($ast->params["stop"], True) . ";\n";
		
		$step = NULL; /* i.e. not known at compilation time */
		if(empty($ast->params["step"]))
			$step = 1;
		else if((count($ast->params["step"]) == 1) and ($ast->params["step"][0] instanceof TextNode))
			$step = $ast->params["step"][0]->text + 0;
		else
			$code .= "\$${loopname}_step = " . _transcompile($ast->params["step"], True) . ";\n";
		
		if(!empty($ast->params["counter"]))
			$code .= "\$${loopname}_countername = " . _transcompile($ast->params["counter"], True) . ";\n";
		
		$loopbody = empty($ast->params["counter"]) ? "" : "\$ste->set_var_by_name(\$${loopname}_countername, \$${loopname}_counter);\n";
		$loopbody .= _transcompile($ast->sub);
		$loopbody = indent_code("{\n" . loopbody(indent_code($loopbody)) . "\n}\n");
		
		if($step === NULL)
		{
			$code .= "if(\$${loopname}_step == 0)\n\tthrow new \Exception('Runtime Error: step can not be 0 in <ste:for>.');\n";
			$code .= "if(\$${loopname}_step > 0)\n{\n";
			$code .= "\tfor(\$${loopname}_counter = \$${loopname}_start; \$${loopname}_counter <= \$${loopname}_stop; \$${loopname}_counter += \$${loopname}_step)\n";
			$code .= $loopbody;
			$code .= "\n}\nelse\n{\n";
			$code .= "\tfor(\$${loopname}_counter = \$${loopname}_start; \$${loopname}_counter >= \$${loopname}_stop; \$${loopname}_counter += \$${loopname}_step)\n";
			$code .= $loopbody;
			$code .= "\n}\n";
		}
		else if($step == 0)
			throw new ParseCompileError("Transcompile Error: step can not be 0 in <ste:for>.", $ast->tpl, $ast->offset);
		else if($step > 0)
			$code .= "for(\$${loopname}_counter = \$${loopname}_start; \$${loopname}_counter <= \$${loopname}_stop; \$${loopname}_counter += $step)\n$loopbody\n";
		else
			$code .= "for(\$${loopname}_counter = \$${loopname}_start; \$${loopname}_counter >= \$${loopname}_stop; \$${loopname}_counter += $step)\n$loopbody\n";
		
		return $code;
	},
	"foreach" => function($ast)
	{
		$loopname = "foreachloop_" . str_replace(".", "_", uniqid("",True));
		$code = "";
		
		if(empty($ast->params["array"]))
			throw new ParseCompileError("Transcompile Error: array not given in <ste:foreach>.", $ast->tpl, $ast->offset);
		$code .= "\$${loopname}_arrayvar = " . _transcompile($ast->params["array"], True) . ";\n";
		
		if(empty($ast->params["value"]))
			throw new ParseCompileError("Transcompile Error: value not given in <ste:foreach>.", $ast->tpl, $ast->offset);
		$code .= "\$${loopname}_valuevar = " . _transcompile($ast->params["value"], True) . ";\n";
		
		if(!empty($ast->params["key"]))
			$code .= "\$${loopname}_keyvar = " . _transcompile($ast->params["key"], True) . ";\n";
		
		if(!empty($ast->params["counter"]))
			$code .= "\$${loopname}_countervar = " . _transcompile($ast->params["counter"], True) . ";\n";
		
		$loopbody = "";
		$code .= "\$${loopname}_array = \$ste->get_var_by_name(\$${loopname}_arrayvar);\n";
		$code .= "if(!is_array(\$${loopname}_array))\n\t\$${loopname}_array = array();\n";
		if(!empty($ast->params["counter"]))
		{
			$code .= "\$${loopname}_counter = -1;\n";
			$loopbody .= "\$${loopname}_counter++;\n\$ste->set_var_by_name(\$${loopname}_countervar, \$${loopname}_counter);\n";
		}
		
		$loopbody .= "\$ste->set_var_by_name(\$${loopname}_valuevar, \$${loopname}_value);\n";
		if(!empty($ast->params["key"]))
			$loopbody .= "\$ste->set_var_by_name(\$${loopname}_keyvar, \$${loopname}_key);\n";
		$loopbody .= "\n";
		$loopbody .= _transcompile($ast->sub);
		$loopbody = "{\n" . loopbody(indent_code($loopbody)) . "\n}\n";
		
		$code .= "foreach(\$${loopname}_array as \$${loopname}_key => \$${loopname}_value)\n$loopbody\n";
		
		return $code;
	},
	"infloop" => function($ast)
	{
		return "while(True)\n{\n" . loopbody(indent_code(_transcompile($ast->sub)) . "\n}") . "\n";
	},
	"break" => function($ast)
	{
		return "throw new \\ste\\BreakException();\n";
	},
	"continue" => function($ast)
	{
		return "throw new \\ste\\ContinueException();\n";
	},
	"block" => function($ast)
	{
		if(empty($ast->params["name"]))
			throw new ParseCompileError("Transcompile Error: name missing in <ste:block>.", $ast->tpl, $ast->offset);
		
		$blknamevar = "blockname_" . str_replace(".", "_", uniqid("", True));
		
		$code = "\$${blknamevar} = " . _transcompile($ast->params["name"], True) . ";\n";
		
		$tmpblk = uniqid("", True);
		$code .= "\$ste->blocks['$tmpblk'] = array_pop(\$outputstack);\n\$ste->blockorder[] = '$tmpblk';\n\$outputstack = array('');\n\$outputstack_i = 0;\n";
		
		$code .= _transcompile($ast->sub);
		
		$code .= "\$ste->blocks[\$${blknamevar}] = array_pop(\$outputstack);\n";
		$code .= "if(array_search(\$${blknamevar}, \$ste->blockorder) === FALSE)\n\t\$ste->blockorder[] = \$${blknamevar};\n\$outputstack = array('');\n\$outputstack_i = 0;\n";
		
		return $code;
	},
	"load" => function($ast)
	{
		if(empty($ast->params["name"]))
			throw new ParseCompileError("Transcompile Error: name missing in <ste:load>.", $ast->tpl, $ast->offset);
		
		return "\$outputstack[\$outputstack_i] .= \$ste->load(" . _transcompile($ast->params["name"], True) . ");\n";
	},
	"mktag" => function($ast)
	{
		if(empty($ast->params["name"]))
			throw new ParseCompileError("Transcompile Error: name missing in <ste:mktag>.", $ast->tpl, $ast->offset);
		
		$tagname = _transcompile($ast->params["name"], True);
		
		$fxbody = "\$outputstack = array(); \$outputstack_i = 0;\$ste->vars['_tag_parameters'] = \$params;\n";
		
		if(!empty($ast->params["mandatory"]))
		{
			$code .= "\$outputstack[] = '';\n\$outputstack_i++;\n";
			$code .= _transcompile($ast->params["mandatory"]);
			$code .= "\$outputstack_i--;\n\$mandatory_params = explode('|', array_pop(\$outputstack));\n";
			
			$fxbody .= "foreach(\$mandatory_params as \$mp)\n{\n\tif(!isset(\$params[\$mp]))\n\t\tthrow new \Exception(\"Runtime Error: \$mp missing in <ste:\" . $tagname . \">. Stop.\");\n}";
		}
		
		$fxbody .= _transcompile($ast->sub);
		$fxbody .= "return array_pop(\$outputstack);";
		
		$code .= "\$tag_fx = function(\$ste, \$params, \$sub) use (\$mandatory_params)\n{\n" . indent_code($fxbody) . "\n};\n";
		$code .= "\$ste->register_tag($tagname, \$tag_fx);\n";
		
		return $code;
	},
	"tagcontent" => function($ast)
	{
		return "\$outputstack[\$outputstack_i] .= \$sub(\$ste);";
	},
	"set" => function($ast)
	{
		if(empty($ast->params["var"]))
			throw new ParseCompileError("Transcompile Error: var missing in <ste:set>.", $ast->tpl, $ast->offset);
		
		$code = "\$outputstack[] = '';\n\$outputstack_i++;\n";
		$code .= _transcompile($ast->sub);
		$code .= "\$outputstack_i--;\n";
		
		$code .= "\$ste->set_var_by_name(" . _transcompile($ast->params["var"], True) . ", array_pop(\$outputstack));\n";
		
		return $code;
	},
	"calc" => function($ast)
	{
		$code = "\$outputstack[] = '';\n\$outputstack_i++;\n";
		$code .= _transcompile($ast->sub);
		$code .= "\$outputstack_i--;\n\$outputstack[\$outputstack_i] .= \$ste->calc(array_pop(\$outputstack));\n";
		
		return $code;
	}
);

function escape_text($text)
{
	return addcslashes($text, "\r\n\t\$\0..\x1f\\'\"\x7f..\xff");
}

function _transcompile($ast, $no_outputstack = False) /* The real transcompile function, does not add boilerplate code. */
{
	$code = "";
	global $ste_builtins;
	
	$text_and_var_buffer = array();
	
	foreach($ast as $node)
	{
		if($node instanceof TextNode)
			$text_and_var_buffer[] = '"' . escape_text($node->text) . '"';
		else if($node instanceof VariableNode)
			$text_and_var_buffer[] = $node->transcompile();
		else if($node instanceof TagNode)
		{
			if(!empty($text_and_var_buffer))
			{
				$code .= "\$outputstack[\$outputstack_i] .= " . implode (" . ", $text_and_var_buffer) . ";\n";
				$text_and_var_buffer = array();
			}
			if(isset($ste_builtins[$node->name]))
				$code .= $ste_builtins[$node->name]($node);
			else
			{
				$paramarray = "parameters_" . str_replace(".", "_", uniqid("", True));
				$code .= "\$$paramarray = array();\n";
				
				foreach($node->params as $pname => $pcontent)
					$code .= "\$${paramarray}['" . escape_text($pname) . "'] = " . _transcompile($pcontent, True) . ";\n";
				
				$code .= "\$outputstack[\$outputstack_i] .= \$ste->call_tag('" . escape_text($node->name) . "', \$${paramarray}, ";
				$code .= empty($node->sub) ? "function(\$ste) { return ''; }" : transcompile($node->sub);
				$code .= ");\n";
			}
		}
	}
	
	if(!empty($text_and_var_buffer))
	{
		if(!$no_outputstack)
			$code .= "\$outputstack[\$outputstack_i] .= ";
		$code .= implode (" . ", $text_and_var_buffer);
		if(!$no_outputstack)
			$code .= ";\n";
		$text_and_var_buffer = array();
	}
	else if($no_outputstack)
	{
		$code = "\"\"";
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
 * 	$ast - The abstract syntax tree to transcompile.
 * 
 * Returns:
 * 	PHP code. The PHP code is an anonymous function expecting a <STECore> instance as its parameter and returns a string (everything that was not pached into a section).
 */
function transcompile($ast) /* Transcompile and add some boilerplate code. */
{
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

/*
 * Class: StorageAccess
 * An interface.
 * A StorageAccess implementation is used to access the templates from any storage.
 * This means, that you are not limited to store the Templates inside directories, you can also use a database or something else.
 */
interface StorageAccess
{
	/*
	 * Function: load
	 * Loading a template.
	 * 
	 * Parameters:
	 * 	$tpl - The name of the template.
	 * 	&$mode - Which mode is preferred? One of the <Template modes>.
	 * 	         If <MODE_SOURCE>, the raw sourcecode is expected, if <MODE_TRANSCOMPILED> the transcompiled template *as a callable function* (expecting an <STECore> instance as first parameter) is expected.
	 * 	         If the transcompiled version is not available or older than the source, you can set this parameter to <MODE_SOURCE> and return the source.
	 * 
	 * Returns:
	 * 	Either the sourcecode or a callable function (first, and only parameter: an <STECore> instance).
	 */
	public function load($tpl, &$mode);
	
	/*
	 * Function: save
	 * Saves a template.
	 * 
	 * Parameters:
	 * 	$tpl -The name of the template.
	 * 	$data - The data to be saved.
	 * 	$mode - A <Template mode> constant.
	 */
	public function save($tpl, $data, $mode);
}

/*
 * Class: FilesystemStorageAccess
 * The default <StorageAccess> implementation for loading / saving templates into a directory structure.
 */
class FilesystemStorageAccess implements StorageAccess
{
	protected $sourcedir;
	protected $transcompileddir;
	
	/*
	 * Constructor: __construct
	 * 
	 * Parameters:
	 * 	$src - The directory with the sources (Writing permissions are not mandatory, because STE does not save template sources).
	 * 	$transc - The directory with the transcompiled templates (the PHP instance / the HTTP Server needs writing permissions to this directory).
	 */
	public function __construct($src, $transc)
	{
		$this->sourcedir        = $src;
		$this->transcompileddir = $transc;
	}
	
	public function load($tpl, &$mode)
	{
		$src_fn    = $this->sourcedir        . "/" . $tpl;
		$transc_fn = $this->transcompileddir . "/" . $tpl . ".php";
		
		if($mode == MODE_SOURCE)
		{
			$content = @file_get_contents($src_fn);
			if($content === False)
				throw new \Exception("Template not found.");
			return $content;
		}
		
		$src_stat    = @stat($src_fn);
		$transc_stat = @stat($transc_fn);
		
		if(($src_stat === False) and ($transc_stat === False))
			throw new \Exception("Template not found.");
		else if($transc_stat === False)
		{
			$mode = MODE_SOURCE;
			return file_get_contents($src_fn);
		}
		else if($src_stat === False)
		{
			include($transc_fn);
			return $transcompile_fx;
		}
		else
		{
			if($src_stat["mtime"] > $transc_stat["mtime"])
			{
				$mode = MODE_SOURCE;
				return file_get_contents($src_fn);
			}
			else
			{
				include($transc_fn);
				return $transcompile_fx;
			}
		}
	}
	
	public function save($tpl, $data, $mode)
	{
		$fn = (($mode == MODE_SOURCE) ? $this->sourcedir : $this->transcompileddir) . "/" . $tpl . (($mode == MODE_TRANSCOMPILED) ? ".php" : "");
		@mkdir(dirname($fn), 0777, True);
		file_put_contents($fn, "<?php \$transcompile_fx = $data; ?>");
	}
}

class BreakException    extends \Exception { }
class ContinueException extends \Exception { }

/*
 * Class: STECore
 * The Core of STE
 */
class STECore
{
	private $tags;
	private $storage_access;
	private $cur_tpl_dir;
	
	/*
	 * Variables: Public variables
	 * 
	 * $blocks - Associative array of blocks (see the language definition).
	 * $blockorder - The order of the blocks (an array)
	 * $vars - Associative array of all template variables. Use this to pass data to your templates.
	 */
	public $blocks;
	public $blockorder;
	public $vars;
	
	/*
	 * Constructor: __construct
	 * 
	 * Parameters:
	 * 	$storage_access - An Instance of a <StorageAccess> implementation.
	 */
	public function __construct($storage_access)
	{
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
	 * 	$name - The name of the tag.
	 * 	$callback - A callable function (This must tage three parameters: The <STECore> instance, an associative array of parameters, and a function representing the tags content(This expects the <STECore> instance as its only parameter and returns its text result, i.e to get the text, you neeed to call this function with the <STECore> instance as a parameter)).
	 */
	public function register_tag($name, $callback)
	{
		if(!is_callable($callback))
			throw new \Exception("Can not register tag \"$name\", not callable.");
		if(empty($name))
			throw new \Exception("Can not register tag, empty name.");
		$this->tags[$name] = $callback;
	}
	
	/*
	 * Function: call_tag
	 * Calling a custom tag (builtin ones can not be called)
	 * 
	 * Parameters:
	 * 	$name - The Tag's name
	 * 	$params - Associative array of parameters
	 * 	$sub - A callable function (expecting an <STECore> instance as it's parameter) that represents the tag's content.
	 * 
	 * Returns:
	 * 	The output of the tag.
	 */
	public function call_tag($name, $params, $sub)
	{
		if(!isset($this->tags[$name]))
			throw new \Exception("Can not call tag \"$name\": Does not exist.");
		return call_user_func($this->tags[$name], $this, $params, $sub);
	}
	
	public function calc($expression)
	{
		return calc_rpn(shunting_yard($expression));
	}
	
	/*
	 * Function: exectemplate
	 * Executes a template and returns the result. The huge difference to <load> is that this function will also output all blocks.
	 * 
	 * Parameters:
	 * 	$tpl - The name of the template to execute.
	 * 
	 * Returns:
	 * 	The output of the template.
	 */
	public function exectemplate($tpl)
	{
		$output = "";
		$lastblock = $this->load($tpl);
		
		foreach($this->blockorder as $blockname)
			$output .= $this->blocks[$blockname];
		
		return $output . $lastblock;
	}
	
	/*
	 * Function: get_var_reference
	 * Get a reference to a template variable using a variable name.
	 * This can be used,if your custom tag takes a variable name as a parameter.
	 * 
	 * Parameters:
	 * 	$name - The variables name.
	 * 	$create_if_not_exist - Should the variable be created, if it does not exist? Otherwise NULL will be returned, if the variable does not exist.
	 * 
	 * Returns:
	 * 	A Reference to the variable.
	 */
	
	public function &get_var_reference($name, $create_if_not_exist)
	{
		$ref = $this->_get_var_reference($this->vars, $name, $create_if_not_exist);
		return $ref;
	}
	
	private function &_get_var_reference(&$from, $name, $create_if_not_exist)
	{
		$bracket_open = strpos($name, "[");
		if($bracket_open === False)
		{
			if(isset($from[$name]) or $create_if_not_exist)
			{
				$ref = &$from[$name];
				return $ref;
			}
			else
				return NULL;
		}
		else
		{
			$old_varname = $varname;
			$bracket_close = strpos($name, "]", $bracket_open);
			if($bracket_close === FALSE)
				throw new Excpeption("Runtime Error: Invalid varname \"$varname\". Missing closing \"]\".");
			$varname = substr($name, 0, $bracket_open);
			$name    = substr($name, $bracket_open + 1, $bracket_close - $bracket_open - 1) . substr($name, $bracket_close + 1);
			if(!is_array($from[$varname]))
			{
				if($create_if_not_exist)
					$from[$varname] = array();
				else
					return NULL;
			}
			try
			{
				$ref = &$this->_get_var_reference($from[$varname], $name, $create_if_not_exist);
				return $ref;
			}
			catch(Exception $e)
			{
				throw new Excpeption("Runtime Error: Invalid varname \"$old_varname\". Missing closing \"]\".");
			}
		}
	}
	
	/*
	 * Function: set_var_by_name
	 * Set a template variable by its name.
	 * This can be used,if your custom tag takes a variable name as a parameter.
	 * 
	 * Parameters:
	 * 	$name - The variables name.
	 * 	$val - The new value.
	 */
	public function set_var_by_name($name, $val)
	{
		$ref = &$this->_get_var_reference($this->vars, $name, True);
		$ref = $val;
	}
	
	/*
	 * Function: get_var_by_name
	 * Get a template variable by its name.
	 * This can be used,if your custom tag takes a variable name as a parameter.
	 * 
	 * Parameters:
	 * 	$name - The variables name.
	 * 
	 * Returns:
	 * 	The variables value.
	 */
	public function get_var_by_name($name)
	{
		$ref = $this->_get_var_reference($this->vars, $name, False);
		return $ref === NULL ? "" : $ref;
	}
	
	/*
	 * Function: load
	 * Load a template and return its result (blocks not included, use <exectemplate> for this).
	 * 
	 * Parameters:
	 * 	$tpl - The name of the template to be loaded.
	 * 	$quiet - If true, do not output anything and do notmodify the blocks. This can be useful to load custom tags that are programmed in STE T/PL. Default: false.
	 * 
	 * Returns:
	 * 	The result of the template (if $quiet == false).
	 */
	public function load($tpl, $quiet=False)
	{
		$tpldir_b4 = $this->cur_tpl_dir;
		
		/* Resolve ".", ".." and protect from possible LFI */
		$tpl = str_replace("\\", "/", $tpl);
		if($tpl[0] != "/")
			$tpl = $this->cur_tpl_dir . "/" . $tpl;
		$pathex = array_filter(explode("/", $tpl), function($s) { return ($s != ".") and (!empty($s)); });
		$pathex = array_merge($pathex);
		while(($i = array_search("..", $pathex)) !== False)
		{
			if($i == 0)
				$pathex = array_slice($pathex, 1);
			else
				$pathex = array_merge(array_slice($pathex, 0, $i), array_slice($pathex, $i + 2));
		}
		$tpl = implode("/", $pathex);
		$this->cur_tpl_dir = dirname($tpl);
		
		if($quiet)
		{
			$blocks_back     = clone $this->blocks;
			$blockorder_back = clone $this->blockorder;
		}
		
		$mode = MODE_TRANSCOMPILED;
		$content = $this->storage_access->load($tpl, $mode);
		if($mode == MODE_SOURCE)
		{
			$content = precompile($content);
			try
			{
				$ast     = parse($content, $tpl, 0);
				$transc  = transcompile($ast);
			}
			catch(ParseCompileError $e)
			{
				$e->rewrite($content);
				throw $e;
			}
			$this->storage_access->save($tpl, $transc, MODE_TRANSCOMPILED);
			eval("\$content = $transc;");
		}
		
		$output = $content($this);
		
		$this->cur_tpl_dir = $tpldir_b4;
		
		if($quiet)
		{
			$this->blocks     = $blocks_back;
			$this->blockorder = $blockorder_back;
		}
		else
			return $output;
	}
	
	/*
	 * Function: evalbool
	 * Test, if a text represents false (an empty / only whitespace text) or true (everything else).
	 * 
	 * Parameters:
	 * 	$txt - The text to test.
	 * 
	 * Returns:
	 * 	true/false.
	 */
	public function evalbool($txt)
	{
		return trim($txt) != "";
	}
}

class STEStandardLibrary
{
	static public function _register_lib($ste)
	{
		foreach(get_class_methods(__CLASS__) as $method)
			if($method[0] != "_")
				$ste->register_tag($method, array(__CLASS__, $method));
	}
	
	static public function escape($ste, $params, $sub)
	{
		return htmlentities($sub($ste), ENT_QUOTES, "UTF-8");
	}
	
	static public function strlen($ste, $params, $sub)
	{
		return strlen($sub($ste));
	}
	
	static public function arraylen($ste, $params, $sub)
	{
		if(empty($params["array"]))
			throw new \Exception("Runtime Error: missing array parameter in <ste:arraylen>.");
		$a = $ste->get_var_by_name($params["array"]);
		return (is_array($a)) ? count($a) : "";
	}
	
	static public function inc($ste, $params, $sub)
	{
		if(empty($params["var"]))
			throw new \Exception("Runtime Error: missing var parameter in <ste:inc>.");
		$ref = $ste->_get_var_reference($ste->vars, $params["var"]);
		$ref++;
	}
	
	static public function dec($ste, $params, $sub)
	{
		if(empty($params["var"]))
			throw new \Exception("Runtime Error: missing var parameter in <ste:dec>.");
		$ref = $ste->_get_var_reference($ste->vars, $params["var"]);
		$ref--;
	}
	
	static public function date($ste, $params, $sub)
	{
		return @strftime($sub($ste), empty($params["timestamp"]) ? @time() : (int) $params["timestamp"]);
	}
}

?>
