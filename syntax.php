<?php
/**
 * Register Plugin: display pretty pictures of registers
 *
 * @license   GPL-3
 * @author    Mike Frysinger <vapier@gentoo.org>
 */

if (!defined("DOKU_PLUGIN")) define("DOKU_PLUGIN", DOKU_INC."lib/plugins/");
require_once(DOKU_PLUGIN . "syntax.php");
require_once(DOKU_PLUGIN . "register/register.php");

class syntax_plugin_register extends DokuWiki_Syntax_Plugin
{
	public function getInfo()
	{
        return confToHash(dirname(__FILE__).'/info.txt');
	}
	public function getType() { return "protected"; }
	public function getSort() { return 333; }
	public function connectTo($mode)
	{
		$this->Lexer->addEntryPattern("<register>", $mode, "plugin_register");
	}
	public function postConnect()
	{
		/*
		$keywords = array(
			"register", "long desc", "short desc", "perms", "addr", "reset",
			"length", "bit range", "bit name", "bit desc", "bit flags"
		);
		foreach ($keywords as $k)
			$this->Lexer->addPattern("$k = ", "plugin_register");
		*/
		$this->Lexer->addExitPattern("</register>", "plugin_register");
	}

	public function handle($match, $state, $pos, &$handler)
	{
		switch ($state) {
		/*1*/case DOKU_LEXER_ENTER:     return array($state, $match);
		/*2*/case DOKU_LEXER_MATCHED:   return array();
		/*3*/case DOKU_LEXER_UNMATCHED: return array($state, $match);
		/*4*/case DOKU_LEXER_EXIT:      return array($state, $match);
		/*5*/case DOKU_LEXER_SPECIAL:   return array();
		}
		return false;
	}

	/* XXX: remember, php error will not flush renderer and thus no debug messages */
	private $debug = 0;
	private function _msg(&$renderer, $type, $msg)
	{
		$bt = debug_backtrace();
		$renderer->doc .= "<br><b>{register plugin $type}:".$bt[2]["function"]."():".$bt[1]["line"].": $msg</b><br>";
		unset($bt);
	}
	private function err(&$renderer, $msg) { $this->_msg($renderer, "error", $msg); }
	private function dbg(&$renderer, $msg) { if ($this->debug) $this->_msg($renderer, "debug", $msg); }

	private function get_output_pieces(&$renderer, $match)
	{
		global $conf;

		$dir = $conf["mediadir"] . "/register";
		$hash = md5(serialize($match));
		$file = "$dir/$hash.png";
		$url = DOKU_BASE . "lib/exe/fetch.php?cache=$cache&amp;media=" . urlencode("register:$hash.png");

		if (!io_mkdir_p($dir)) {
			$this->err($renderer, "failed to create output dir '$dir'");
			return false;
		}

		return array($file, $url);
	}
	private function push_bit(&$bits, &$bit)
	{
		if (!array_key_exists("name", $bit))
			$bit["name"] = "ERROR:UNDEF";
		if (!array_key_exists("desc", $bit))
			$bit["desc"] = "";
		if (!array_key_exists("flags", $bit))
			$bit["flags"] = "";
		$range = explode(" ", $bit["range"]);
		if (count($range) == 1)
			$range[1] = $range[0];
		array_push($bits, array($range[0], $range[1], $bit["name"], $bit["desc"], $bit["flags"]));
		$bit = array();
	}
	private function parse_match(&$renderer, $match)
	{
		$keys = array();
		$bits = array();
		$bit = array();
		$lines = explode("\n", $match);
		foreach ($lines as $l) {
			if ($l == "")
				continue;
			$this->dbg($renderer, "line: $l");
			$val = strstr($l, " = ");
			$key = substr($l, 0, strlen($l) - strlen($val));
			$val = str_replace("\\n", "\n", substr($val, 3));
			if ($key == "xml")
				return $this->parse_match_xml($renderer, $match);
			if (substr($key, 0, 4) == "bit ") {
				$subkey = substr($key, 4);
				if ($subkey == "range" && count($bit) > 0)
					$this->push_bit($bits, $bit);
				$bit[$subkey] = $val;
				$this->dbg($renderer, "BIT[$subkey] = $val");
			} else {
				$keys[$key] = $val;
				$this->dbg($renderer, "KEY[$key] = $val");
			}
		}
		if (count($bit) > 0)
			$this->push_bit($bits, $bit);
		if (!array_key_exists("long desc", $keys))
			$keys["long desc"] = "";
		if (!array_key_exists("addr", $keys))
			$keys["addr"] = "";
		if (!array_key_exists("reset", $keys))
			$keys["reset"] = "undef";
		if (!array_key_exists("perms", $keys))
			$keys["perms"] = "";
		if (!array_key_exists("short desc", $keys))
			$keys["short desc"] = "";
		return array($keys, $bits);
	}
	private function parse_match_xml(&$renderer, $match)
	{
		$xml_path = DOKU_PLUGIN . "register/xml/";

		$lines = explode("\n", $match);
		foreach ($lines as $l) {
			$val = strstr($l, " = ");
			$key = substr($l, 0, strlen($l) - strlen($val));
			$val = str_replace("\\n", "\n", substr($val, 3));
			$keys[$key] = $val;
		}

		if (eregi_replace("[-.a-z0-9]*", "", $keys["xml"]) != "") {
			$this->err($renderer, "invalid xml file name '".$keys["xml"]."'");
			return false;
		}

		$keys["xml"] .= (substr($keys["xml"], -4) == ".xml" ? "" : ".xml");
		if (file_exists($xml_path . "ADSP-" . $keys["xml"]))
			$keys["xml"] = "ADSP-" . $keys["xml"];
		$xml = $xml_path . $keys["xml"];
		$this->dbg($renderer, "XML = $xml");

		$fp = fopen($xml, "r");
		if (!$fp) {
			$this->err($renderer, "unable to read xml file '$xml'");
			return false;
		}
		global $adi_xml_search, $adi_xml_state, $adi_xml_result;
		$adi_xml_search = $keys["register"];
		$adi_xml_state = 1;
		$adi_xml_result = array();
		$xml_parser = xml_parser_create();
		xml_set_element_handler($xml_parser, "adi_register_xml_parse", "");
		while (($data = fread($fp, 8192)) && $adi_xml_state) {
			if (!xml_parse($xml_parser, $data, feof($fp))) {
				$this->err($renderer, "XML error: %s at line %d",
					xml_error_string(xml_get_error_code($xml_parser)),
					xml_get_current_line_number($xml_parser));
			}
		}
		xml_parser_free($xml_parser);
		fclose($fp);

		$attrs = $adi_xml_result["attrs"];
		$keys["long desc"] = $attrs["DESCRIPTION"];
		$keys["short desc"] = $attrs["DEF-COMMENT"]; /*DEF-HEADER*/
		$keys["addr"] = $attrs["WRITE-ADDRESS"];
		$keys["length"] = $attrs["BIT-SIZE"];
		$keys["reset"] = "undef";
		$bits = array_reverse($adi_xml_result["bits"]);
		return array($keys, $bits);
	}
	private function generate_image(&$renderer, $match, $file)
	{
		/* if the output file exists, nothing for us to do */
		if (is_readable($file))
			return true;

		$ret = $this->parse_match($renderer, $match);
		if (!$ret)
			return false;
		list($keys, $bits) = $ret;

		/*
			register = WDOG_CTL
			long desc = Watchdog Control Register
			short desc = moo
			perms = RW
			addr = 0xFFC00200
			reset = 0x0AD0
			length = 16
			bit range = 15 15
			bit name = WDR0
			bit desc = 0 - Watchdog timer has not expired\n1 - Watchdog timer has expired
			bit flags = W1C
			bit range = 11 4
			bit name = WDEN
			bit desc = 0xAD - Counter disabled\nAll other values - Counter enabled
		*/

		$reg = new register(
			$keys["register"], $keys["long desc"], $keys["addr"], $keys["reset"],
			$keys["length"], $keys["perms"], $keys["short desc"],
			$bits
		);
		if (!$reg->render($file))
			return false;
		unset($reg);

		/* pass the WDOG_CTL back up for "alt" in <img>  ? */
		//return array($keys["register"], $keys["long desc"]);
		return true;
	}
	public function render($mode, &$renderer, $data)
	{
		if ($mode != "xhtml")
			return false;

		/* convert the stuff returned from handle() */
		if (gettype($data) != "array") {
			$this->err($renderer, "incoming data from handle() is not an array");
			return false;
		}
		list($state, $match) = $data;
		$this->dbg($renderer, "state: $state match: $match");
		if ($state != DOKU_LEXER_UNMATCHED)
			return true;	/* nothing to do */

		/* setup the file / url locations */
		$pieces = $this->get_output_pieces($renderer, $match);
		if ($pieces == false) {
			$this->err($renderer, "get_output_pieces() failed");
			return false;
		}
		list($file, $url) = $pieces;

		/* generate the image */
		if (!$this->generate_image($renderer, $match, $file)) {
			$this->err($renderer, "generate_image() failed");
			return false;
		}

		/* present the image! */
		$renderer->doc .= "<img src='$url' class='media' title='Register Bit Breakout' alt='Register'/>";
		return true;
	}
}

function adi_register_xml_parse($parser, $name, $attrs)
{
	global $adi_xml_search, $adi_xml_state, $adi_xml_result;

	if ($name != "REGISTER")
		return false;

	switch ($adi_xml_state) {
	case 1:
		if ($attrs["NAME"] == $adi_xml_search) {
			$adi_xml_result["attrs"] = $attrs;
			$adi_xml_result["bits"] = array();
			$adi_xml_state = 2;
		}
		break;

	case 2:
		if ($attrs["PARENT"] != $adi_xml_search) {
			$adi_xml_state = 0;
			return false;
		}
		$adi_xml_result["bits"][$attrs["BIT-POSITION"]] = array(
			$attrs["BIT-POSITION"] - 1 + $attrs["BIT-SIZE"],
			$attrs["BIT-POSITION"],
			$attrs["NAME"],
			$attrs["DESCRIPTION"],
			""
		);
		break;
	}
}

?>
